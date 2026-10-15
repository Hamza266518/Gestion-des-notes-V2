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

    private function creerEtudiant(Groupe $groupe, string $nom, string $numero): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'scan.tmp.' . bin2hex(random_bytes(5)) . '@ifp.ma',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->createdUsers[] = $user->id;

        $e = Etudiant::create([
            'user_id'             => $user->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'status'              => 'active',
            'nom_prenom'          => $nom,
            'cin'                 => 'CIN' . bin2hex(random_bytes(5)),
            'numero_inscription'  => $numero,
        ]);
        $this->createdEtudiants[] = $e->id;

        return $e;
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
            app(\App\Services\PublicationLockService::class),
            app(\App\Services\ScanEtudiantMatcher::class),
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
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

        // A real student, but searched in a perimeter that excludes them.
        $etudiant = Etudiant::whereNotNull('groupe_id')
            ->where('annee_academique_id', $this->annee->id)
            ->first();
        if (!$etudiant) {
            $this->markTestSkipped('No student fixture.');
        }

        $emptyPerimetre = collect();
        $found = $matcher->match($emptyPerimetre, $this->annee->id, $etudiant->nom_prenom, null);

        $this->assertNull($found, 'An empty perimeter must match nobody.');
    }

    public function test_a_known_name_inside_the_perimeter_is_matched(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

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
        $found = $matcher->match($groupeIds, $this->annee->id, $etudiant->nom_prenom, null);

        $this->assertNotNull($found, 'A student inside the perimeter must still be matched.');
        $this->assertSame($etudiant->id, $found->id);
    }

    public function test_an_unknown_name_matches_nobody(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

        $groupeIds = Groupe::where('annee_academique_id', $this->annee->id)->pluck('id');
        $ghost = 'ZZINTROUVABLEFANTOME' . bin2hex(random_bytes(4));

        $found = $matcher->match($groupeIds, $this->annee->id, $ghost);

        $this->assertNull($found);
    }

    public function test_the_registration_number_wins_over_the_name(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

        $groupe = Groupe::where('annee_academique_id', $this->annee->id)->first();
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        $numero = 'NUM' . bin2hex(random_bytes(4));
        $etudiant = $this->creerEtudiant(
            $groupe,
            'NUM' . bin2hex(random_bytes(3)) . ' Cible Numero',
            $numero
        );

        // The name is unreadable, but the sheet's N identifies the student.
        $found = $matcher->match(collect([$groupe->id]), $this->annee->id, '', $numero);

        $this->assertNotNull($found, 'A readable N must be enough to match.');
        $this->assertSame($etudiant->id, $found->id);
    }

    public function test_the_registration_number_is_bounded_by_the_group(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

        $groupe = Groupe::where('annee_academique_id', $this->annee->id)->first();
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        $numero = 'HORS' . bin2hex(random_bytes(4));
        $this->creerEtudiant($groupe, 'HORS' . bin2hex(random_bytes(3)) . ' Autre', $numero);

        // Searching a different group: the N must not carry the grade across.
        $autre = Groupe::where('annee_academique_id', $this->annee->id)
            ->where('id', '!=', $groupe->id)
            ->first();
        if (!$autre) {
            $this->markTestSkipped('Need a second group fixture.');
        }

        $trouve = $matcher->match(collect([$autre->id]), $this->annee->id, '', $numero);
        $this->assertNull($trouve, 'A correct N from another group must not match.');
    }

    public function test_a_wrong_number_falls_back_to_the_name(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

        $groupe = Groupe::where('annee_academique_id', $this->annee->id)->first();
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        $nom = 'FALLBACK' . bin2hex(random_bytes(3)) . ' Bon Nom';
        $etudiant = $this->creerEtudiant($groupe, $nom, 'VRAI' . bin2hex(random_bytes(4)));

        // Gemini misread the number; the name is still correct.
        $trouve = $matcher->match(collect([$groupe->id]), $this->annee->id, $nom, 'FAUX' . bin2hex(random_bytes(4)));

        $this->assertNotNull($trouve, 'A wrong N must not prevent a name match.');
        $this->assertSame($etudiant->id, $trouve->id);
    }

    public function test_an_ambiguous_name_is_left_unresolved(): void
    {
        $matcher = app(\App\Services\ScanEtudiantMatcher::class);

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

        $found = $matcher->match(collect([$groupe->id]), $this->annee->id, $sharedName, null);

        $this->assertNull($found, 'Two equally-good candidates must not be guessed between.');
    }
}