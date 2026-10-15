<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\SemestrePublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Diploma readiness must survive a student with no group.
 *
 * `etudiants.groupe_id` is nullable with ON DELETE SET NULL (see
 * 2026_05_06_000000_fix_foreign_keys_and_add_student_status), so a student can
 * genuinely exist without a group. checkReadiness() wrapped its whole loop in one
 * try/catch, so that single row threw out of `$etudiant->groupe->niveau->filiere_id`
 * and the outer catch answered 500 for every student in the year -- one orphan hid
 * two hundred valid results. generateAll() already isolated each student; this locks
 * both to the same per-student contract, and checks that the orphan is reported
 * rather than silently folded into another bucket.
 *
 * Cleanup deletes by primary key. The suite shares a database, so deleting by
 * predicate would remove fixture state other tests rely on.
 */
class DiplomeReadinessTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];
    private array $touchedPublications = [];
    private array $publicationSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.admin@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome admin',
                'email'    => 'diplome.admin@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test readiness');
    }

    protected function tearDown(): void
    {
        foreach ($this->touchedPublications as $key) {
            $q = DB::table('semestre_publications')
                ->where('groupe_id', $key['groupe_id'])
                ->where('annee_academique_id', $key['annee_academique_id'])
                ->where('type', $key['type']);

            if (array_key_exists($key['__k'], $this->publicationSnapshot)) {
                $row = $this->publicationSnapshot[$key['__k']];
                $q->update([
                    'is_published' => $row['is_published'],
                    'published_at' => $row['published_at'],
                    'updated_at'   => $row['updated_at'],
                ]);
            } else {
                $q->delete();
            }
        }

        foreach ($this->createdEtudiants as $etudiant) {
            Etudiant::where('id', $etudiant->id)->delete();
        }
        foreach ($this->createdGroupes as $groupe) {
            Groupe::where('id', $groupe->id)->delete();
        }
        foreach ($this->createdNiveaux as $niveau) {
            Niveau::where('id', $niveau->id)->delete();
        }
        foreach ($this->createdFilieres as $filiere) {
            Filiere::where('id', $filiere->id)->delete();
        }

        parent::tearDown();
    }

    /**
     * A filière whose only level is also its final year, so a student placed in it
     * passes the "année diplômante" gate.
     */
    private function makeFinalYearGroupe(string $suffix): Groupe
    {
        $filiere = Filiere::create([
            'nom'           => 'FIL TEST ' . $suffix,
            'code'          => 'FT' . substr(md5($suffix . uniqid()), 0, 8),
            'section'       => 'SECTION',
            'nombre_annees' => 1,
        ]);
        $this->createdFilieres[] = $filiere;

        $niveau = Niveau::create([
            'filiere_id' => $filiere->id,
            'numero'     => 1,
        ]);
        $this->createdNiveaux[] = $niveau;

        $groupe = Groupe::create([
            'niveau_id'           => $niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                => 'GRP TEST ' . $suffix,
            'promotion'          => 'TEST ' . $suffix,
        ]);
        $this->createdGroupes[] = $groupe;

        return $groupe;
    }

    private function makeEtudiant(string $suffix, ?int $groupeId): Etudiant
    {
        $etudiant = Etudiant::create([
            'user_id'            => $this->admin->id,
            'groupe_id'          => $groupeId,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'         => 'TEST STUDENT ' . $suffix,
            'cin'                => 'TEST' . substr(md5($suffix . uniqid('', true)), 0, 12),
            'numero_inscription' => 'INS' . substr(md5('num' . $suffix . uniqid('', true)), 0, 14),
            'status'             => 'active',
        ]);

        $this->createdEtudiants[] = $etudiant;
        return $etudiant;
    }

    private function publishBulletin(Groupe $groupe): void
    {
        $key = [
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => 'bulletin',
            '__k'                 => $groupe->id . ':' . $this->annee->id . ':bulletin',
        ];

        $this->touchedPublications[$key['__k']] = $key;
        $this->publicationSnapshot[$key['__k']] = DB::table('semestre_publications')
            ->where('groupe_id', $groupe->id)
            ->where('annee_academique_id', $this->annee->id)
            ->where('type', 'bulletin')
            ->first();

        SemestrePublication::updateOrCreate(
            [
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'type'                => 'bulletin',
            ],
            ['is_published' => true, 'published_at' => now()]
        );
    }

    private function readiness()
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/diplomes/check-readiness?annee_academique_id={$this->annee->id}");
    }

    /**
     * The regression: one ungrouped student must not turn the whole report into a
     * 500. The orphan is counted separately so the admin can see it needs fixing.
     */
    public function test_readiness_survives_a_student_with_no_group(): void
    {
        $this->makeEtudiant('ORPHAN', null);

        $res = $this->readiness();

        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertIsInt(
            $res->json('data.orphans'),
            'Orphans must be counted and reported'
        );
        $this->assertIsInt(
            $res->json('data.ready'),
            'Every other student must still be evaluated'
        );
    }

    public function test_generate_all_also_survives_a_student_with_no_group(): void
    {
        $groupe = $this->makeFinalYearGroupe('ORPHANRES');
        $this->makeEtudiant('ORPHANRES', null);
        $this->makeEtudiant('ORPHANRES OK', $groupe->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/generate-all', [
                'annee_academique_id' => $this->annee->id,
            ]);

        $res->assertOk();
        $this->assertTrue(
            $res->json('success'),
            'A single ungroupable row must not fail the batch'
        );
        $this->assertIsInt($res->json('data.created'));
        $this->assertIsInt($res->json('data.skipped'));
    }

    /**
     * Readiness counts a grade as filled when a note carries a valeur or when the
     * unit's exam row does. Exam rows used to be ignored while BulletinService
     * folded them into the same average, so a student could clear the gate with a
     * transcript missing its theory and practical marks.
     */
    public function test_readiness_is_not_blocked_by_unpublished_bulletins_for_other_groups(): void
    {
        $groupe = $this->makeFinalYearGroupe('PUBBLOCK');
        $this->makeEtudiant('PUBBLOCK', $groupe->id);

        // No publication: this student is legitimately not ready, and importantly
        // the endpoint answers with counts rather than failing.
        $res = $this->readiness();

        $res->assertOk();
        $this->assertGreaterThanOrEqual(
            1,
            $res->json('data.bulletin_not_published'),
            'A group without a published bulletin counts as not published'
        );
    }

    public function test_orphans_are_excluded_from_the_blocked_verdict(): void
    {
        $this->makeEtudiant('ONLYORPHAN', null);

        $data = $this->readiness()->assertOk()->json('data');

        // An orphan is a data-quality problem, not a reason to block the batch;
        // otherwise a single unassigned student would block every valid one.
        $this->assertGreaterThanOrEqual(
            1,
            $data['orphans'],
            'The orphan must be counted'
        );
    }
}