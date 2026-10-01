<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A scanned sheet must never create anybody.
 *
 * A scanned name is only linked to a student who already exists in the scanned
 * control's filière/année. Anything else stays "introuvable" so an admin can
 * handle it from the Students page.
 */
class ScanIntrouvableTest extends TestCase
{
    private User $formateur;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->formateur = User::where('email', 'scan.formateur@sweep.test')->first()
            ?? User::create([
                'name'     => 'Scan formateur',
                'email'    => 'scan.formateur@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'formateur',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    protected function tearDown(): void
    {
        Etudiant::whereIn('id', $this->createdEtudiants)->forceDelete();
        User::whereIn('id', $this->createdUsers)->delete();
        parent::tearDown();
    }

    public function test_no_route_can_create_a_student_from_a_scan(): void
    {
        // The endpoint must not exist at all: 404, and definitely not a 2xx.
        foreach (['scan/check-duplicates', 'scan/etudiant'] as $path) {
            $this->actingAs($this->formateur)
                ->getJson('/api/formateur/' . $path)
                ->assertNotFound();

            $this->actingAs($this->formateur)
                ->postJson('/api/formateur/' . $path, [
                    'controle_id'        => 1,
                    'nom_prenom'         => 'Should Never Exist',
                    'groupe_id'          => 1,
                    'numero_inscription' => 'GHOST-001',
                ])
                ->assertNotFound();
        }

        $this->assertFalse(
            Etudiant::where('numero_inscription', 'GHOST-001')->exists(),
            'No student may be created through a scan route.'
        );
    }

    public function test_perimeter_only_covers_the_scanned_filiere_and_year(): void
    {
        // The helper that decides what a scanned name may be matched against.
        $controller = new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
        );
        $ref = new \ReflectionMethod($controller, 'perimetrePourControle');
        $ref->setAccessible(true);

        $controle = \App\Models\Controle::with('sequence.unite')->first();
        if (!$controle?->sequence?->unite) {
            $this->markTestSkipped('No control fixture.');
        }

        [$groupeIds, $anneeId] = $ref->invoke($controller, $controle->id);

        $unite = $controle->sequence->unite;
        $expected = Groupe::whereHas('niveau', fn($q) => $q
                ->where('filiere_id', $unite->filiere_id)
                ->where('numero', $unite->numero_annee))
            ->where('annee_academique_id', $anneeId)
            ->pluck('id');

        $this->assertEqualsCanonicalizing(
            $expected->all(),
            $groupeIds->all(),
            'Only groups of the control\'s filière/année may be searched.'
        );
    }

    public function test_a_name_outside_the_perimeter_is_not_matched(): void
    {
        $controller = new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
        );
        $m = new \ReflectionMethod($controller, 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);

        // A real student, but searched in a perimeter that excludes them.
        $etudiant = Etudiant::whereNotNull('groupe_id')
            ->where('annee_academique_id', $this->annee->id)
            ->first();
        if (!$etudiant) {
            $this->markTestSkipped('No student fixture.');
        }

        $emptyPerimetre = collect();
        $found = $m->invoke($controller, $etudiant->nom_prenom, $emptyPerimetre, $this->annee->id, $this->formateur->id);

        $this->assertNull($found, 'An empty perimeter must match nobody.');
    }

    public function test_a_known_name_inside_the_perimeter_is_matched(): void
    {
        $controller = new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
        );
        $m = new \ReflectionMethod($controller, 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);

        // Pick a student whose name is unique inside its own perimeter, since
        // a name shared by two students is deliberately left unresolved.
        $etudiant = null;
        foreach (Etudiant::whereNotNull('groupe_id')->where('annee_academique_id', $this->annee->id)->get() as $cand) {
            $count = Etudiant::where('groupe_id', $cand->groupe_id)
                ->where('annee_academique_id', $this->annee->id)
                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . mb_strtolower($cand->nom_prenom) . '%'])
                ->count();
            if ($count === 1) {
                $etudiant = $cand;
                break;
            }
        }
        if (!$etudiant) {
            $this->markTestSkipped('No uniquely-named student fixture.');
        }

        $groupeIds = collect([$etudiant->groupe_id]);
        $found = $m->invoke($controller, $etudiant->nom_prenom, $groupeIds, $this->annee->id, $this->formateur->id);

        $this->assertNotNull($found, 'A student inside the perimeter must still be matched.');
        $this->assertSame($etudiant->id, $found->id);
    }

    public function test_an_unknown_name_matches_nobody(): void
    {
        $controller = new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
        );
        $m = new \ReflectionMethod($controller, 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);

        $groupeIds = Groupe::where('annee_academique_id', $this->annee->id)->pluck('id');
        $ghost = 'ZZINTROUVABLEFANTOME' . bin2hex(random_bytes(4));

        $found = $m->invoke($controller, $ghost, $groupeIds, $this->annee->id, $this->formateur->id);

        $this->assertNull($found);
    }

    public function test_an_ambiguous_name_is_left_unresolved(): void
    {
        $controller = new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
        );
        $m = new \ReflectionMethod($controller, 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);

        // Two students sharing the same name, both inside the perimeter.
        $groupe = Groupe::where('annee_academique_id', $this->annee->id)->first();
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        $sharedName = 'DUP' . bin2hex(random_bytes(3)) . ' Ambigu';
        foreach ([1, 2] as $i) {
            $user = User::create([
                'name'     => $sharedName,
                'email'    => 'dup.tmp.' . $i . '.' . bin2hex(random_bytes(4)) . '@ifp.ma',
                'password' => Hash::make('secret123'),
                'role'     => 'etudiant',
            ]);
            $this->createdUsers[] = $user->id;

            $e = Etudiant::create([
                'user_id'             => $user->id,
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'status'              => 'active',
                'nom_prenom'          => $sharedName,
                'cin'                 => 'DUP' . bin2hex(random_bytes(5)),
                'numero_inscription'  => 'DUP-' . bin2hex(random_bytes(4)),
            ]);
            $this->createdEtudiants[] = $e->id;
        }

        $found = $m->invoke($controller, $sharedName, collect([$groupe->id]), $this->annee->id, $this->formateur->id);

        $this->assertNull($found, 'Two equally-good candidates must not be guessed between.');
    }
}