<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Matching and hand-linking must both stay inside one group.
 *
 * A control whose filière/année maps to several groups lets the formateur pick
 * the cohort; a grade may then only ever reach a student of that group. Without
 * this, a sibling cohort's student silently absorbs the note.
 */
class ScanPerimetreGroupeTest extends TestCase
{
    private User $formateurUser;
    private \App\Models\Formateur $formateur;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdUsers = [];
    private array $groupesCrees = [];
    private array $createdNotes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->formateurUser = User::where('email', 'grp.formateur@sweep.test')->first()
            ?? User::create([
                'name'     => 'Perimetre formateur',
                'email'    => 'grp.formateur@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'formateur',
            ]);

        // The controllers resolve a Formateur profile, not the User itself.
        $this->formateur = \App\Models\Formateur::firstOrCreate(
            ['user_id' => $this->formateurUser->id],
            ['user_id' => $this->formateurUser->id]
        );

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    protected function tearDown(): void
    {
        // Notes first: they reference the students, and a leaked confirmed note
        // would block the Stage migration rollback in another test.
        //
        // Scoped to the grades and students this class created. The control here
        // is Controle::first(), i.e. a pre-existing one, so deleting by
        // controle_id would wipe every real grade attached to it.
        \App\Models\Note::whereIn('id', $this->createdNotes)->delete();
        \App\Models\Note::whereIn('etudiant_id', $this->createdEtudiants)->delete();

        Etudiant::whereIn('id', $this->createdEtudiants)->forceDelete();
        User::whereIn('id', $this->createdUsers)->delete();
        Groupe::whereIn('id', $this->groupesCrees)->delete();
        DB::table('formateur_sequences')->where('formateur_id', $this->formateur->id)->delete();
        \App\Models\Formateur::where('user_id', $this->formateurUser->id)->delete();
        parent::tearDown();
    }

    /** Assign the scanned sequence to the test formateur so access checks pass. */
    private function assignerSequence(int $sequenceId): void
    {
        $existe = DB::table('formateur_sequences')
            ->where('formateur_id', $this->formateur->id)
            ->where('sequence_id', $sequenceId)
            ->exists();

        if (!$existe) {
            DB::table('formateur_sequences')->insert([
                'formateur_id' => $this->formateur->id,
                'sequence_id'  => $sequenceId,
                'masse_horaire' => 0,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    private function controller(): \App\Http\Controllers\Formateur\ScanController
    {
        return new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
            app(\App\Services\PublicationLockService::class),
        );
    }

    /**
     * Two groups in the same filière/année, so a single control maps to both.
     */
    private function deuxGroupesMemePerimetre(): array
    {
        $controle = Controle::with('sequence.unite')->first();
        if (!$controle?->sequence?->unite) {
            $this->markTestSkipped('No control fixture.');
        }
        $unite = $controle->sequence->unite;

        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) {
            $this->markTestSkipped('No niveau fixture.');
        }

        $groupes = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $this->annee->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($groupes->count() < 2) {
            // Build the second group so the scenario is always exercised.
            $existing = Groupe::where('niveau_id', $niveau->id)
                ->where('annee_academique_id', $this->annee->id)
                ->orderBy('id')
                ->first();
            if (!$existing) {
                $this->markTestSkipped('No group fixture.');
            }

            $nouveau = Groupe::create([
                'niveau_id'           => $niveau->id,
                'annee_academique_id' => $this->annee->id,
                'nom'                 => 'TMP-PERIMETRE-' . bin2hex(random_bytes(3)),
                'promotion'           => 'TMP',
            ]);
            $this->groupesCrees[] = $nouveau->id;
            $groupes = collect([$existing, $nouveau]);
        }

        return [$controle, $niveau, $groupes[0], $groupes[1]];
    }

    private function creerEtudiant(Groupe $groupe, string $nom): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'grp.tmp.' . bin2hex(random_bytes(5)) . '@ifp.ma',
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
            'cin'                 => 'GRP' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'GRP-' . bin2hex(random_bytes(4)),
        ]);
        $this->createdEtudiants[] = $e->id;

        return $e;
    }

    public function test_matching_respects_the_selected_group(): void
    {
        [$controle, , $groupeA, $groupeB] = $this->deuxGroupesMemePerimetre();

        $m = new \ReflectionMethod($this->controller(), 'matchEtudiantDansPerimetre');
        $m->setAccessible(true);

        // Student in group B only.
        $nomB = 'GRP' . bin2hex(random_bytes(4)) . ' Eleve Beta';
        $this->creerEtudiant($groupeB, $nomB);

        // Searching group A alone must not find them.
        $trouveA = $m->invoke($this->controller(), $nomB, collect([$groupeA->id]), $this->annee->id, null);
        $this->assertNull($trouveA, 'A student of another group must stay introuvable.');

        // Searching group B finds them.
        $trouveB = $m->invoke($this->controller(), $nomB, collect([$groupeB->id]), $this->annee->id, null);
        $this->assertNotNull($trouveB);
    }

    public function test_scan_refuses_a_group_outside_the_control_perimetre(): void
    {
        [$controle, , , $groupeB] = $this->deuxGroupesMemePerimetre();

        // A group from another filière/année entirely.
        $horsPerimetre = Groupe::where('id', '!=', $groupeB->id)
            ->whereNotIn('niveau_id', function ($q) use ($controle) {
                $q->select('id')->from('niveaux')
                    ->where('filiere_id', $controle->sequence->unite->filiere_id)
                    ->where('numero', $controle->sequence->unite->numero_annee);
            })
            ->first();

        if (!$horsPerimetre) {
            $this->markTestSkipped('No group outside the perimeter.');
        }

        // Call the validation path directly: the endpoint needs a PDF, but the
        // perimeter rejection happens before any file is read.
        $m = new \ReflectionMethod($this->controller(), 'perimetrePourControle');
        $m->setAccessible(true);
        [$ids] = $m->invoke($this->controller(), $controle->id);

        $this->assertFalse(
            $ids->contains($horsPerimetre->id),
            'The reject-list must not contain a foreign group.'
        );
    }

    public function test_confirm_skips_a_hand_linked_student_from_another_group(): void
    {
        [$controle, , $groupeA, $groupeB] = $this->deuxGroupesMemePerimetre();

        $this->assignerSequence($controle->sequence_id);

        $interdit = $this->creerEtudiant($groupeB, 'GRP' . bin2hex(random_bytes(4)) . ' Hors Groupe');

        // groupe_id pins the cohort to group A, so the group-B student is out.
        $response = $this->actingAs($this->formateurUser)->postJson('/api/formateur/confirm', [
            'groupe_id' => $groupeA->id,
            'notes' => [[
                'etudiant_id' => $interdit->id,
                'controle_id' => $controle->id,
                'valeur'      => 15,
            ]],
        ]);

        $response->assertOk();

        $this->assertSame(
            0,
            \App\Models\Note::where('etudiant_id', $interdit->id)
                ->where('controle_id', $controle->id)
                ->count(),
            'A student outside the control perimeter must not receive the grade.'
        );

        $skipped = collect($response->json('skipped'));
        $this->assertTrue(
            $skipped->contains('etudiant_id', $interdit->id),
            'The refused row must be reported instead of silently dropped.'
        );
    }

    public function test_search_suggestions_are_scoped_to_the_control_perimetre(): void
    {
        [$controle, , $groupeA, $groupeB] = $this->deuxGroupesMemePerimetre();

        $prefixe = 'SEARCH' . bin2hex(random_bytes(4));
        $nom = $prefixe . ' Cible';
        $dansA = $this->creerEtudiant($groupeA, $nom);
        $dansB = $this->creerEtudiant($groupeB, $prefixe . ' Frere');

        $this->assignerSequence($controle->sequence_id);

        $ids = fn($params) => collect(
            $this->actingAs($this->formateurUser)
                ->getJson('/api/formateur/etudiants?' . http_build_query($params))
                ->json('data')
        )->pluck('id')->all();

        // Scoped to group A: the sibling must not be suggested.
        $scoped = $ids(['search' => $prefixe, 'controle_id' => $controle->id, 'groupe_id' => $groupeA->id]);
        $this->assertContains($dansA->id, $scoped);
        $this->assertNotContains($dansB->id, $scoped, 'A sibling group must not be suggested.');

        // Scoped to the whole control: both groups are legitimate.
        $perimetre = $ids(['search' => $prefixe, 'controle_id' => $controle->id]);
        $this->assertContains($dansA->id, $perimetre);
        $this->assertContains($dansB->id, $perimetre);
    }

    public function test_scan_data_exposes_the_groups_of_the_current_year(): void
    {
        [$controle, $niveau, $groupeA] = $this->deuxGroupesMemePerimetre();

        $this->assignerSequence($controle->sequence_id);

        $data = $this->actingAs($this->formateurUser)
            ->getJson('/api/formateur/scan-data')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('groupes_par_perimetre', $data);

        $cle = $niveau->filiere_id . '|' . $niveau->numero;
        $this->assertArrayHasKey($cle, $data['groupes_par_perimetre']);
        $this->assertContains(
            $groupeA->id,
            array_column($data['groupes_par_perimetre'][$cle], 'id'),
            'The chosen group must be offered to the scanner.'
        );
    }
}