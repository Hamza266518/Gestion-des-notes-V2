<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\User;
use App\Services\PublicationLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The closed-period lock is per group, not per level.
 *
 * The original check asked "is any group at this level published?" and answered
 * for the whole level, so publishing one cohort locked its siblings -- an
 * instructor was refused a grade the admin could still edit from the same
 * screen. These tests pin the per-group rule, and keep the guarantee that a
 * confirm payload can never write into a published group.
 */
class ScanPublicationLockTest extends TestCase
{
    private User $formateurUser;
    private \App\Models\Formateur $formateur;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdUsers = [];
    private array $createdGroupes = [];
    private array $createdPublications = [];
    private array $publicationSnapshots = [];
    private array $createdNotes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->formateurUser = User::where('email', 'pub.formateur@sweep.test')->first()
            ?? User::create([
                'name'     => 'Publication formateur',
                'email'    => 'pub.formateur@sweep.test',
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
        // Only rows this class created: the grades it inserted directly, plus
        // anything the confirm endpoint wrote for the students it created.
        // Deleting by controle_id would wipe every pre-existing grade of the
        // reused fixture controls, which is silent data loss whenever the suite
        // points at a seeded database.
        Note::whereIn('id', $this->createdNotes)->delete();
        Note::whereIn('etudiant_id', $this->createdEtudiants)->delete();

        SemestrePublication::whereIn('id', $this->createdPublications)->delete();
        Etudiant::whereIn('id', $this->createdEtudiants)->forceDelete();
        User::whereIn('id', $this->createdUsers)->delete();
        Groupe::whereIn('id', $this->createdGroupes)->delete();
        DB::table('formateur_sequences')->where('formateur_id', $this->formateur->id)->delete();
        \App\Models\Formateur::where('user_id', $this->formateurUser->id)->delete();

        $this->restorePublications();

        parent::tearDown();
    }

    /**
     * The suite shares one database, and other classes leave publication rows
     * behind for the very groups these tests run against. A sibling that is
     * already published makes a "per group" scenario read as "per level", so
     * every group a scenario touches is snapshotted first and restored here.
     */
    private function snapPublication(int $groupeId, int $anneeId, string $type): void
    {
        $key = $groupeId . ':' . $anneeId . ':' . $type;
        if (array_key_exists($key, $this->publicationSnapshots)) return;

        $row = SemestrePublication::where('groupe_id', $groupeId)
            ->where('annee_academique_id', $anneeId)
            ->where('type', $type)
            ->first();

        $this->publicationSnapshots[$key] = $row ? $row->attributesToArray() : null;
    }

    /** Drops every publication of a group for the current year, remembering what was there. */
    private function neutralise(int $groupeId, ?string $type = null): void
    {
        foreach ($type !== null ? [$type] : ['notes_s1', 'notes_s2', 'bulletin'] as $t) {
            $this->snapPublication($groupeId, $this->annee->id, $t);
            SemestrePublication::where('groupe_id', $groupeId)
                ->where('annee_academique_id', $this->annee->id)
                ->where('type', $t)
                ->delete();
        }
    }

    /** Same, for every group a control's level owns. */
    private function neutraliseNiveau(Controle $controle): void
    {
        foreach ($this->groupesDuNiveau($controle) as $groupe) {
            $this->neutralise($groupe->id);
        }
    }

    private function restorePublications(): void
    {
        foreach ($this->publicationSnapshots as $key => $row) {
            [$groupeId, $anneeId, $type] = explode(':', $key);
            $groupeId = (int) $groupeId;
            $anneeId = (int) $anneeId;

            if ($row === null) continue;

            $q = SemestrePublication::where('groupe_id', $groupeId)
                ->where('annee_academique_id', $anneeId)
                ->where('type', $type);

            if ($q->exists()) {
                $q->update([
                    'is_published' => $row['is_published'],
                    'published_at' => $row['published_at'],
                    'updated_at'   => $row['updated_at'],
                ]);
            } else {
                SemestrePublication::create([
                    'groupe_id'           => $groupeId,
                    'annee_academique_id' => $anneeId,
                    'type'                => $type,
                    'is_published'        => $row['is_published'],
                    'published_at'        => $row['published_at'],
                    'created_at'          => $row['created_at'],
                    'updated_at'          => $row['updated_at'],
                ]);
            }
        }
    }

    private function assignerSequence(int $sequenceId): void
    {
        // updateOrInsert, not insert: an interrupted earlier run can leave the
        // row behind, and the unique index would then fail every later run.
        DB::table('formateur_sequences')->updateOrInsert(
            ['formateur_id' => $this->formateur->id, 'sequence_id' => $sequenceId],
            ['masse_horaire' => 0, 'updated_at' => now()]
        );
        DB::table('formateur_sequences')
            ->where('formateur_id', $this->formateur->id)
            ->where('sequence_id', $sequenceId)
            ->update(['created_at' => now()]);
    }

    private function controller(): \App\Http\Controllers\Formateur\ScanController
    {
        return new \App\Http\Controllers\Formateur\ScanController(
            app(\App\Services\GeminiService::class),
            app(\App\Services\NoteParserService::class),
            app(\App\Services\PublicationLockService::class),
            app(\App\Services\ScanEtudiantMatcher::class),
        );
    }

    private function verrouille(Controle $controle, $groupeId): bool
    {
        return app(PublicationLockService::class)->verrouilleGroupe($controle, $groupeId);
    }

    private function publier(Groupe $groupe, string $type): void
    {
        // The suite shares one database and other classes leave publication rows
        // behind, so clear this key first: a leaked row for the same
        // groupe/annee/type would turn the helper into a duplicate-key error
        // instead of the assertion under test.
        $this->snapPublication($groupe->id, $this->annee->id, $type);
        SemestrePublication::where('groupe_id', $groupe->id)
            ->where('annee_academique_id', $this->annee->id)
            ->where('type', $type)
            ->delete();

        $publication = SemestrePublication::create([
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => $type,
            'is_published'        => true,
            'published_at'        => now(),
        ]);
        $this->createdPublications[] = $publication->id;
    }

    private function niveauDe(Controle $controle): ?\App\Models\Niveau
    {
        $unite = $controle->sequence?->unite;
        if (!$unite) return null;

        return \App\Models\Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
    }

    /** Every group of the level owning this control, in the current year. */
    private function groupesDuNiveau(Controle $controle): array
    {
        $niveau = $this->niveauDe($controle);
        if (!$niveau) return [];

        return Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $this->annee->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * A control plus two of its sibling groups, so a per-group rule can be told
     * apart from a per-level one. A second group is created when the level only
     * has one.
     *
     * @return array{0: Controle, 1: Groupe, 2: Groupe}
     */
    private function controleAvecDeuxGroupes(): array
    {
        foreach (Controle::with('sequence.unite')->get() as $controle) {
            if (!$controle->sequence?->unite) continue;

            $groupes = $this->groupesDuNiveau($controle);

            // A leaked publication on any sibling would make a per-group
            // scenario read as per-level, so the level starts clean.
            $this->neutraliseNiveau($controle);

            if (count($groupes) >= 2) {
                return [$controle, $groupes[0], $groupes[1]];
            }

            if (count($groupes) === 1) {
                $niveau = $this->niveauDe($controle);
                $frere = Groupe::create([
                    'niveau_id'           => $niveau->id,
                    'annee_academique_id' => $this->annee->id,
                    'nom'                 => 'PUB-TMP-' . bin2hex(random_bytes(3)),
                    'promotion'           => 'PUBTMP',
                ]);
                $this->createdGroupes[] = $frere->id;
                $this->touchedControles[] = $controle->id;

                return [$controle, $groupes[0], $frere];
            }
        }

        $this->markTestSkipped('Need a level with at least one group in the current year.');
    }

    /** Any control belonging to a semester. */
    private function controleSemestre(int $semestre): Controle
    {
        $controle = Controle::with('sequence.unite')->get()
            ->first(fn ($c) => (int) ($c->sequence?->unite?->semestre) === $semestre);

        if (!$controle) {
            $this->markTestSkipped("Need a semester $semestre control.");
        }

        return $controle;
    }

    /** Creates a grade and remembers it so teardown can remove exactly it. */
    private function creerNote(int $etudiantId, int $controleId, float $valeur): Note
    {
        $note = Note::create([
            'etudiant_id' => $etudiantId,
            'controle_id' => $controleId,
            'valeur'      => $valeur,
        ]);

        $this->createdNotes[] = $note->id;

        return $note;
    }

    private function creerEtudiant(Groupe $groupe, string $nom): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'pub.tmp.' . bin2hex(random_bytes(5)) . '@ifp.ma',
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
            'cin'                 => 'PUB' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'PUB-' . bin2hex(random_bytes(4)),
        ]);
        $this->createdEtudiants[] = $e->id;

        return $e;
    }

    // ---------------------------------------------------------------------
    // The regression: a sibling group being published must not close this one.
    // ---------------------------------------------------------------------

    public function test_publishing_a_sibling_group_does_not_lock_another_group(): void
    {
        [$controle, $groupeA, $groupeB] = $this->controleAvecDeuxGroupes();

        $this->publier($groupeA, 'bulletin');

        $this->assertTrue($this->verrouille($controle, $groupeA->id), 'The published group must be locked.');
        $this->assertFalse(
            $this->verrouille($controle, $groupeB->id),
            'Publishing one group must not close its sibling at the same level.'
        );
    }

    public function test_a_sibling_publication_does_not_block_editing_a_grade(): void
    {
        [$controle, $groupeA, $groupeB] = $this->controleAvecDeuxGroupes();

        $this->assignerSequence($controle->sequence_id);
        $this->publier($groupeA, 'bulletin');

        $eleveB = $this->creerEtudiant($groupeB, 'PUB' . bin2hex(random_bytes(4)) . ' Frere');

        $note = $this->creerNote($eleveB->id, $controle->id, 10);

        $this->actingAs($this->formateurUser)
            ->putJson('/api/formateur/notes/' . $note->id, ['valeur' => 14])
            ->assertOk();

        $this->assertSame(14.0, (float) $note->fresh()->valeur);
    }

    public function test_publishing_a_group_still_locks_that_group(): void
    {
        [$controle, $groupeA] = $this->controleAvecDeuxGroupes();

        $this->assignerSequence($controle->sequence_id);
        $this->publier($groupeA, 'bulletin');

        $eleve = $this->creerEtudiant($groupeA, 'PUB' . bin2hex(random_bytes(4)) . ' Cible');

        $note = $this->creerNote($eleve->id, $controle->id, 10);

        $this->actingAs($this->formateurUser)
            ->putJson('/api/formateur/notes/' . $note->id, ['valeur' => 14])
            ->assertStatus(403);

        $this->assertSame(10.0, (float) $note->fresh()->valeur);
    }

    // ---------------------------------------------------------------------
    // Semester separation is unchanged. The fixture only carries semester 2
    // controls, which is enough: publishing an S1 type must leave an S2 control
    // open, and a bulletin must close it. The type mapping itself is asserted
    // directly in the unit test so it is covered even without fixtures.
    // ---------------------------------------------------------------------

    public function test_notes_s1_publication_does_not_close_semester_two(): void
    {
        $s2 = $this->controleSemestre(2);
        $this->neutraliseNiveau($s2);
        $groupe = $this->groupesDuNiveau($s2)[0] ?? null;
        if (!$groupe) {
            $this->markTestSkipped('Need a group in the current year.');
        }

        $this->publier($groupe, 'notes_s1');

        $this->assertFalse(
            $this->verrouille($s2, $groupe->id),
            'An S1 publication must leave S2 open for entry.'
        );
    }

    public function test_a_bulletin_closes_semester_two(): void
    {
        $s2 = $this->controleSemestre(2);
        $this->neutraliseNiveau($s2);
        $groupe = $this->groupesDuNiveau($s2)[0] ?? null;
        if (!$groupe) {
            $this->markTestSkipped('Need a group in the current year.');
        }

        $this->publier($groupe, 'bulletin');

        $this->assertTrue(
            $this->verrouille($s2, $groupe->id),
            'A published bulletin contains both semesters, so it must close S2 too.'
        );
    }

    public function test_notes_s2_publication_closes_semester_two(): void
    {
        $s2 = $this->controleSemestre(2);
        $this->neutraliseNiveau($s2);
        $groupe = $this->groupesDuNiveau($s2)[0] ?? null;
        if (!$groupe) {
            $this->markTestSkipped('Need a group in the current year.');
        }

        $this->publier($groupe, 'notes_s2');

        $this->assertTrue($this->verrouille($s2, $groupe->id), 'S2 must close on an S2 publication.');
    }

    public function test_a_group_published_twice_is_still_counted_once(): void
    {
        // Publishing both the notes and the bulletin of one group produces two
        // rows for the same groupe_id. The perimeter count must not see that as
        // two locked groups, or a fully published sequence reads as open.
        $s2 = $this->controleSemestre(2);
        $this->neutraliseNiveau($s2);
        $groupe = $this->groupesDuNiveau($s2)[0] ?? null;
        if (!$groupe) {
            $this->markTestSkipped('Need a group in the current year.');
        }

        $this->publier($groupe, 'notes_s2');
        $this->publier($groupe, 'bulletin');

        $locks = app(PublicationLockService::class);

        $this->assertSame(
            [$groupe->id],
            $locks->groupesVerrouilles($s2),
            'A doubly published group must appear once in the locked list.'
        );

        if (count($this->groupesDuNiveau($s2)) === 1) {
            $this->assertTrue(
                $locks->verrouilleTout($s2),
                'A lone published group must lock the control even with two publication rows.'
            );
        }
    }

    // ---------------------------------------------------------------------
    // The status endpoint must not answer for a level the caller does not teach.
    // ---------------------------------------------------------------------

    public function test_publication_status_is_readable_for_an_assigned_control(): void
    {
        [$controle, $groupeA] = $this->controleAvecDeuxGroupes();
        $this->assignerSequence($controle->sequence_id);
        $this->publier($groupeA, 'bulletin');

        $this->actingAs($this->formateurUser)
            ->getJson('/api/formateur/publication-status?controle_id=' . $controle->id)
            ->assertOk()
            ->assertJsonPath('data.groupes_verrouilles', [$groupeA->id]);
    }

    public function test_publication_status_refuses_a_control_outside_my_sequences(): void
    {
        $controle = Controle::with('sequence.unite')->get()
            ->first(fn ($c) => $c->sequence?->unite !== null);

        if (!$controle) {
            $this->markTestSkipped('Need a control.');
        }

        // No assignerSequence() call: the formateur has no claim on this level.
        $this->actingAs($this->formateurUser)
            ->getJson('/api/formateur/publication-status?controle_id=' . $controle->id)
            ->assertStatus(403);

        $this->actingAs($this->formateurUser)
            ->getJson('/api/formateur/publication-status?controle_id=' . $controle->id . '&groupe_id=1')
            ->assertStatus(403);
    }

    public function test_publication_status_rejects_an_unknown_control(): void
    {
        // 422, not the 404 the controller's own guard would return: the exists
        // rule in validate() rejects the id before the find() is reached.
        $this->actingAs($this->formateurUser)
            ->getJson('/api/formateur/publication-status?controle_id=999999999')
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------------
    // The confirm endpoint still refuses a published group, whatever the order.
    // ---------------------------------------------------------------------

    public function test_confirm_accepts_an_open_group_beside_a_published_one(): void
    {
        [$controle, $groupeA, $groupeB] = $this->controleAvecDeuxGroupes();

        $this->publier($groupeA, 'bulletin');

        $this->assignerSequence($controle->sequence_id);

        $eleveB = $this->creerEtudiant($groupeB, 'PUB' . bin2hex(random_bytes(4)) . ' Ouvert');

        // The payload declares the open group, so this must be accepted: the
        // published sibling is no longer relevant to this sheet.
        $this->actingAs($this->formateurUser)->postJson('/api/formateur/confirm', [
            'groupe_id' => $groupeB->id,
            'notes' => [
                ['etudiant_id' => $eleveB->id, 'controle_id' => $controle->id, 'valeur' => 15],
            ],
        ])->assertOk();

        $this->assertSame(1, Note::where('etudiant_id', $eleveB->id)
            ->where('controle_id', $controle->id)->count());
    }

    public function test_confirm_refuses_the_published_group_itself(): void
    {
        [$controle, $groupeA] = $this->controleAvecDeuxGroupes();

        $this->publier($groupeA, 'bulletin');
        $this->assignerSequence($controle->sequence_id);

        $eleve = $this->creerEtudiant($groupeA, 'PUB' . bin2hex(random_bytes(4)) . ' Cible');

        $this->actingAs($this->formateurUser)->postJson('/api/formateur/confirm', [
            'groupe_id' => $groupeA->id,
            'notes' => [
                ['etudiant_id' => $eleve->id, 'controle_id' => $controle->id, 'valeur' => 15],
            ],
        ])->assertStatus(403);

        $this->assertSame(0, Note::where('etudiant_id', $eleve->id)
            ->where('controle_id', $controle->id)->count(),
            'A published group must not receive a grade.');
    }

    /**
     * The check is repeated at confirm on purpose: a sheet can be scanned, left
     * open, and confirmed after the group has been published in between.
     */
    public function test_confirm_still_refuses_when_publication_lands_after_the_scan(): void
    {
        [$controle, $groupeA] = $this->controleAvecDeuxGroupes();

        $this->assignerSequence($controle->sequence_id);
        $eleve = $this->creerEtudiant($groupeA, 'PUB' . bin2hex(random_bytes(4)) . ' Race');

        // The scan happened here, with nothing published yet.
        $this->assertFalse($this->verrouille($controle, $groupeA->id));

        $this->publier($groupeA, 'bulletin');

        $this->actingAs($this->formateurUser)->postJson('/api/formateur/confirm', [
            'groupe_id' => $groupeA->id,
            'notes' => [
                ['etudiant_id' => $eleve->id, 'controle_id' => $controle->id, 'valeur' => 15],
            ],
        ])->assertStatus(403);

        $this->assertSame(0, Note::where('etudiant_id', $eleve->id)
            ->where('controle_id', $controle->id)->count());
    }

    public function test_publishing_every_group_still_locks_the_control_outright(): void
    {
        [$controle, $groupeA, $groupeB] = $this->controleAvecDeuxGroupes();

        $this->publier($groupeA, 'bulletin');
        $this->publier($groupeB, 'bulletin');

        $locks = app(PublicationLockService::class);

        $this->assertTrue(
            $locks->verrouilleTout($controle),
            'With every group published the control must read as closed.'
        );
        $this->assertTrue(
            $locks->verrouillePour($controle, null),
            'A caller with no group must still be blocked when everything is published.'
        );
    }
}
