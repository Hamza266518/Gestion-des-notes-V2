<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\ScanNameCache;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A formateur must not be able to read or redirect another formateur's data.
 *
 * Two separate leaks are covered here:
 *
 *  - the scan-name cache was keyed on the name alone and read back with no
 *    formateur filter, so a shared entry let one instructor's scan resolve to
 *    another instructor's student;
 *  - GET /formateur/etudiants accepted any control id and, with no control at
 *    all, searched every filière in the school.
 */
class FormateurIsolationTest extends TestCase
{
    private AnneeAcademique $annee;
    private array $formateurs = [];
    private array $createdEtudiants = [];
    private array $createdUsers = [];
    private array $createdGroupes = [];
    private array $cacheIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    protected function tearDown(): void
    {
        \App\Models\Note::whereIn('etudiant_id', $this->createdEtudiants)->delete();
        ScanNameCache::whereIn('id', $this->cacheIds)->delete();
        ScanNameCache::whereIn('etudiant_id', $this->createdEtudiants)->delete();

        Etudiant::whereIn('id', $this->createdEtudiants)->forceDelete();
        User::whereIn('id', $this->createdUsers)->delete();
        Groupe::whereIn('id', $this->createdGroupes)->delete();

        foreach ($this->formateurs as $f) {
            DB::table('formateur_sequences')->where('formateur_id', $f->id)->delete();
            \App\Models\Formateur::where('user_id', $f->user_id)->delete();
        }

        parent::tearDown();
    }

    private function creerFormateur(string $tag): array
    {
        // Unique per run: a previous failed teardown must not wedge every rerun.
        $user = User::create([
            'name'     => 'Iso ' . $tag,
            'email'    => 'iso.' . $tag . '.' . bin2hex(random_bytes(5)) . '@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'formateur',
        ]);
        $this->createdUsers[] = $user->id;

        $formateur = \App\Models\Formateur::firstOrCreate(
            ['user_id' => $user->id],
            ['user_id' => $user->id]
        );
        $this->formateurs[] = $formateur;

        return [$user, $formateur];
    }

    private function assignerSequence(\App\Models\Formateur $formateur, int $sequenceId): void
    {
        DB::table('formateur_sequences')->insert([
            'formateur_id' => $formateur->id,
            'sequence_id'  => $sequenceId,
            'masse_horaire' => 0,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function controller(): \App\Http\Controllers\Formateur\ScanController
    {
        return new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
            app(\App\Services\PublicationLockService::class),
        );
    }

    /** A group of the level owning this control, created on demand. */
    private function groupeDuNiveau(Controle $controle): ?Groupe
    {
        $unite = $controle->sequence->unite;

        $niveau = \App\Models\Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) return null;

        $groupe = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $this->annee->id)
            ->orderBy('id')
            ->first();

        if (!$groupe) {
            $groupe = Groupe::create([
                'niveau_id'           => $niveau->id,
                'annee_academique_id' => $this->annee->id,
                'nom'                 => 'TMP-ISO-' . bin2hex(random_bytes(3)),
                'promotion'           => 'TMP',
            ]);
            $this->createdGroupes[] = $groupe->id;
        }

        return $groupe;
    }

    private function creerEtudiant(Groupe $groupe, string $nom): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'iso.tmp.' . bin2hex(random_bytes(5)) . '@ifp.ma',
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
            'cin'                 => 'ISO' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'ISO-' . bin2hex(random_bytes(4)),
        ]);
        $this->createdEtudiants[] = $e->id;

        return $e;
    }

    public function test_the_scan_name_cache_is_not_shared_between_formateurs(): void
    {
        $controle = Controle::with('sequence.unite')->first();
        if (!$controle?->sequence?->unite) {
            $this->markTestSkipped('No control fixture.');
        }

        $groupe = $this->groupeDuNiveau($controle);
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        [$userA, $formateurA] = $this->creerFormateur('a');
        [$userB, $formateurB] = $this->creerFormateur('b');

        $eleve = $this->creerEtudiant($groupe, 'ISOCACHE' . bin2hex(random_bytes(4)) . ' Cible');

        // Formateur A scans this name and the mapping is remembered.
        $nomScanne = 'ISOCACHE' . bin2hex(random_bytes(3)) . ' memorise';
        $cache = ScanNameCache::create([
            'formateur_id'       => $formateurA->id,
            'raw_nom'            => $nomScanne,
            'raw_nom_ar'         => '',
            'raw_nom_normalized' => ScanNameCache::normalizeName($nomScanne),
            'etudiant_id'        => $eleve->id,
        ]);
        $this->cacheIds[] = $cache->id;

        $m = new \ReflectionMethod($this->controller(), 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);
        $groupes = collect([$groupe->id]);

        // The owner still benefits from the cache.
        $pourA = $m->invoke($this->controller(), $nomScanne, $groupes, $this->annee->id, null, $formateurA->id);
        $this->assertNotNull($pourA, 'The instructor who scanned the name keeps the shortcut.');
        $this->assertSame($eleve->id, $pourA->id);

        // Another instructor must not inherit it.
        $pourB = $m->invoke($this->controller(), $nomScanne, $groupes, $this->annee->id, null, $formateurB->id);
        $this->assertNull(
            $pourB,
            'A cached name must never resolve to another instructor\'s student.'
        );
    }

    public function test_search_refuses_a_control_the_caller_does_not_teach(): void
    {
        $controle = Controle::with('sequence.unite')->first();
        if (!$controle?->sequence?->unite) {
            $this->markTestSkipped('No control fixture.');
        }

        // This instructor is assigned nothing at all.
        [$user, ] = $this->creerFormateur('intruder');

        $this->actingAs($user)
            ->getJson('/api/formateur/etudiants?search=a&controle_id=' . $controle->id)
            ->assertStatus(403);
    }

    public function test_search_without_a_control_stays_inside_the_callers_perimetre(): void
    {
        $controle = Controle::with('sequence.unite')->first();
        if (!$controle?->sequence?->unite) {
            $this->markTestSkipped('No control fixture.');
        }

        $groupe = $this->groupeDuNiveau($controle);
        if (!$groupe) {
            $this->markTestSkipped('No group fixture.');
        }

        [$user, $formateur] = $this->creerFormateur('scope');
        $this->assignerSequence($formateur, $controle->sequence_id);

        // A student of this level, and one of another filière.
        $dans = $this->creerEtudiant($groupe, 'ISOSCOPE' . bin2hex(random_bytes(4)) . ' Interne');

        $unite = $controle->sequence->unite;
        $autreNiveau = \App\Models\Niveau::where('numero', $unite->numero_annee)
            ->where('filiere_id', '!=', $unite->filiere_id)
            ->first();
        if (!$autreNiveau) {
            $this->markTestSkipped('Needs a second filière.');
        }

        $groupeExterieur = Groupe::where('niveau_id', $autreNiveau->id)
            ->where('annee_academique_id', $this->annee->id)
            ->orderBy('id')
            ->first();
        if (!$groupeExterieur) {
            $this->markTestSkipped('Needs a group outside the perimeter.');
        }

        $dehors = $this->creerEtudiant($groupeExterieur, 'ISOSCOPE' . bin2hex(random_bytes(3)) . ' Externe');

        $ids = fn () => collect(
            $this->actingAs($user)
                ->getJson('/api/formateur/etudiants?search=ISOSCOPE')
                ->assertOk()
                ->json('data')
        )->pluck('id')->all();

        $trouves = $ids();
        $this->assertContains($dans->id, $trouves, 'The caller\'s own cohort must be searchable.');
        $this->assertNotContains(
            $dehors->id,
            $trouves,
            'Without a control the search must still not reach another filière.'
        );
    }
}