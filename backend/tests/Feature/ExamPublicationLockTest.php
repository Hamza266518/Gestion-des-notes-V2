<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Groupe;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Models\User;
use App\Services\PublicationLockService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Exam grades are frozen by the same publication rule as notes.
 *
 * Exam rows carry no control, so the lock cannot be resolved through a control
 * the way a CC grade can: it comes from the group of the student being graded.
 * Until these guards existed, an admin could publish a group and then still
 * rewrite its exam grades from the grid or from a scan -- the scan being the
 * easier route round, since it writes the very same rows.
 *
 * These tests pin that both write paths refuse, that the refusal is per group
 * rather than per level, and that an unpublished group is untouched.
 */
class ExamPublicationLockTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdExamens = [];
    private array $createdPublications = [];
    private array $createdUsers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $existed = User::where('email', 'pub.exam.admin@sweep.test')->exists();

        $this->admin = User::where('email', 'pub.exam.admin@sweep.test')->first()
            ?? User::create([
                'name'     => 'Publication exam admin',
                'email'    => 'pub.exam.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        // Reused across runs: only claim it for teardown when this run built it,
        // so a leftover account is never mistaken for this test's own row.
        if (!$existed) {
            $this->createdUsers[] = $this->admin->id;
        }

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    protected function tearDown(): void
    {
        // Tracked by id: an earlier run that was interrupted between insert and
        // cleanup must not leave rows behind, and nothing pre-existing in the
        // database may be touched.
        Examen::whereIn('id', $this->createdExamens)->delete();
        Examen::whereIn('etudiant_id', $this->createdEtudiants)->delete();

        SemestrePublication::whereIn('id', $this->createdPublications)->delete();
        Etudiant::whereIn('id', $this->createdEtudiants)->forceDelete();
        User::whereIn('id', $this->createdUsers)->delete();

        parent::tearDown();
    }

    private function publier(Groupe $groupe, string $type): void
    {
        $publication = SemestrePublication::updateOrCreate(
            [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => $type,
            ],
            ['is_published' => true, 'published_at' => now()]
        );

        if (!in_array($publication->id, $this->createdPublications, true)) {
            $this->createdPublications[] = $publication->id;
        }
    }

    /**
     * A student of the given group. Created rather than reused so teardown can
     * remove exactly what this test inserted.
     */
    private function etudiantDans(Groupe $groupe): Etudiant
    {
        $nom = 'EXAM-LOCK-' . bin2hex(random_bytes(4));

        // etudiants.user_id is NOT NULL, so the account row is part of creating
        // the student. Both are tracked for teardown.
        $user = User::create([
            'name'     => $nom,
            'email'    => 'exam.lock.' . bin2hex(random_bytes(5)) . '@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->createdUsers[] = $user->id;

        $etudiant = Etudiant::create([
            'user_id'             => $user->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'status'              => 'active',
            'nom_prenom'          => $nom,
            'cin'                 => 'EX' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'EXL' . bin2hex(random_bytes(4)),
        ]);

        $this->createdEtudiants[] = $etudiant->id;

        return $etudiant;
    }

    /** Any unit of the given semester, preferring one that already has groups. */
    private function uniteSemestre(int $semestre): Unite
    {
        $unites = Unite::where('semestre', $semestre)->where('is_active', true)->get();

        foreach ($unites as $unite) {
            $groupe = Groupe::where('niveau_id', function ($q) use ($unite) {
                $q->select('id')->from('niveaux')
                    ->where('filiere_id', $unite->filiere_id)
                    ->where('numero', $unite->numero_annee);
            })->where('annee_academique_id', $this->annee->id)->first();

            if ($groupe) {
                return $unite;
            }
        }

        if ($unites->isNotEmpty()) {
            return $unites->first();
        }

        $this->markTestSkipped("Need an active unit for semester $semestre.");
    }

    /**
     * Two groups of the same level, so per-group can be told from per-level.
     *
     * @return array{0: Groupe, 1: Groupe}
     */
    private function deuxGroupesDuMemeNiveau(Unite $unite): array
    {
        $niveau = \App\Models\Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();

        if (!$niveau) {
            $this->markTestSkipped('No level for this unit.');
        }

        $groupes = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $this->annee->id)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($groupes->count() < 2) {
            $frere = Groupe::create([
                'niveau_id'           => $niveau->id,
                'annee_academique_id' => $this->annee->id,
                'nom'                 => 'EXAM-LOCK-' . bin2hex(random_bytes(3)),
                'promotion'           => 'EXAMTMP',
            ]);

            return [$groupes->first(), $frere];
        }

        return [$groupes[0], $groupes[1]];
    }

    private function payload(Etudiant $etudiant, Unite $unite, int $semestre, float $valeur = 12.5): array
    {
        return [
            'examens' => [[
                'etudiant_id' => $etudiant->id,
                'valeur'      => $valeur,
                'est_absent'  => false,
            ]],
            'unite_id'            => $unite->id,
            'bloc'                => 1,
            'type'                => 'theorique',
            'semestre'            => $semestre,
            'annee_academique_id' => $this->annee->id,
        ];
    }

    public function test_bulk_refuses_when_notes_s1_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->publier($groupe, 'notes_s1');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 1));

        $response->assertStatus(403);
        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_bulk_refuses_when_bulletin_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        // A bulletin covers both semesters, so it must close an exam grade too.
        $this->publier($groupe, 'bulletin');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 1))
            ->assertStatus(403);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_bulk_refuses_when_notes_s2_is_published_for_a_second_semester_group(): void
    {
        $unite = $this->uniteSemestre(2);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->publier($groupe, 'notes_s2');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 2))
            ->assertStatus(403);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_bulk_refuses_when_only_a_sibling_group_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$publie, $ouvert] = $this->deuxGroupesDuMemeNiveau($unite);

        $this->publier($publie, 'notes_s1');

        $etudiant = $this->etudiantDans($ouvert);

        // Publishing one cohort must never close its classmates: the same rule
        // that was fixed for CC notes applies to exams.
        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 1))
            ->assertStatus(200);

        $this->assertDatabaseHas('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_bulk_refuses_the_whole_batch_when_one_student_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$publie, $ouvert] = $this->deuxGroupesDuMemeNiveau($unite);

        $this->publier($publie, 'notes_s1');

        $bloque = $this->etudiantDans($publie);
        $libre = $this->etudiantDans($ouvert);

        $payload = $this->payload($bloque, $unite, 1);
        $payload['examens'][] = [
            'etudiant_id' => $libre->id,
            'valeur'      => 9.5,
            'est_absent'  => false,
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $payload)
            ->assertStatus(403);

        // Neither student is written: a partial save would leave the admin
        // believing a grade was recorded when it was not.
        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $libre->id,
            'unite_id'    => $unite->id,
        ]);
        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $bloque->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_bulk_saves_when_nothing_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 1, 14.25))
            ->assertStatus(200);

        $examen = Examen::where('etudiant_id', $etudiant->id)
            ->where('unite_id', $unite->id)
            ->first();

        $this->assertNotNull($examen);
        $this->createdExamens[] = $examen->id;

        $this->assertEquals(14.25, $examen->valeur);
    }

    public function test_an_unpublished_row_does_not_block_a_published_group(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        // Same key, is_published false: a publication that was lifted again.
        $publication = SemestrePublication::updateOrCreate(
            [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'notes_s1',
            ],
            ['is_published' => false, 'published_at' => null]
        );
        $this->createdPublications[] = $publication->id;

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload($etudiant, $unite, 1))
            ->assertStatus(200);

        $this->assertDatabaseHas('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_scan_confirm_refuses_when_the_group_is_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->publier($groupe, 'notes_s1');

        // The scan writes the same Examen rows as the grid, so it has to obey
        // the same lock: this is the route the guard is easiest to forget.
        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etudiant->id,
                    'note'        => 15,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_scan_confirm_saves_when_the_group_is_open(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etudiant->id,
                    'note'        => 16,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_a_confirmation_blocked_group_does_not_damage_the_open_ones(): void
    {
        $unite = $this->uniteSemestre(1);
        [$publie, $ouvert] = $this->deuxGroupesDuMemeNiveau($unite);

        $this->publier($publie, 'notes_s1');

        $bloque = $this->etudiantDans($publie);
        $libre = $this->etudiantDans($ouvert);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [
                    ['etudiant_id' => $bloque->id, 'note' => 15, 'est_absent' => false],
                    ['etudiant_id' => $libre->id, 'note' => 11, 'est_absent' => false],
                ],
                'unite_id'            => $unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $libre->id,
            'unite_id'    => $unite->id,
        ]);
    }

    public function test_the_lock_service_reports_the_group_semestre(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);

        $locks = app(PublicationLockService::class);

        $this->assertFalse($locks->semestreVerrouille($groupe->id, $this->annee->id, 1));

        $this->publier($groupe, 'notes_s2');

        // A semester 2 publication must not close semester 1.
        $this->assertFalse($locks->semestreVerrouille($groupe->id, $this->annee->id, 1));
        $this->assertTrue($locks->semestreVerrouille($groupe->id, $this->annee->id, 2));

        $this->publier($groupe, 'bulletin');

        $this->assertTrue($locks->semestreVerrouille($groupe->id, $this->annee->id, 1));
    }

    public function test_a_control_of_another_year_does_not_close_the_exam(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);

        // Guards against the UI and the server judging different years: the
        // exam payload always carries an explicit annee_academique_id.
        $autreAnnee = AnneeAcademique::where('id', '!=', $this->annee->id)->first();
        if (!$autreAnnee) {
            $this->markTestSkipped('Need a second academic year.');
        }

        SemestrePublication::create([
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $autreAnnee->id,
            'type'                => 'notes_s1',
            'is_published'        => true,
            'published_at'        => now(),
        ]);
        $this->createdPublications[] = SemestrePublication::where('annee_academique_id', $autreAnnee->id)
            ->where('groupe_id', $groupe->id)
            ->max('id');

        $this->assertFalse(
            app(PublicationLockService::class)->semestreVerrouille($groupe->id, $this->annee->id, 1)
        );
    }

    public function test_an_absent_exam_is_also_refused_when_published(): void
    {
        $unite = $this->uniteSemestre(1);
        [$groupe] = $this->deuxGroupesDuMemeNiveau($unite);
        $etudiant = $this->etudiantDans($groupe);

        $this->publier($groupe, 'notes_s1');

        // Marking someone absent is still a write to the transcript.
        $payload = $this->payload($etudiant, $unite, 1);
        $payload['examens'][0] = [
            'etudiant_id' => $etudiant->id,
            'valeur'      => null,
            'est_absent'  => true,
        ];

        $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $payload)
            ->assertStatus(403);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $unite->id,
        ]);
    }
}
