<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Manual diploma generation skips every gate except the graduating year.
 *
 * The normal flow refuses a diploma until the bulletin is published, every note
 * is filled, and the average reaches 10. That is the school's rule -- except
 * when it is not, and a student must be graduated anyway. generateManual() is
 * that override: no publication check, no "notes complètes" check, no minimum
 * average -- but a student below the filière's last level keeps no right to a
 * diploma, even handed out by hand.
 *
 * Cleanup deletes by primary key. The suite shares a database, so deleting by
 * predicate would remove fixture state other tests rely on.
 */
class DiplomeManualGenerationTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.manual@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome manual admin',
                'email'    => 'diplome.manual@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test manual generation');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdEtudiants as $etudiant) {
            Diplome::where('etudiant_id', $etudiant->id)->delete();
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
     * is flagged `annee_diplomante` in the candidate list. `$numero` lets a test
     * build a non-final level too (numero < nombre_annees).
     */
    private function makeGroupe(string $suffix, int $numero = 1, int $nombreAnnees = 1): Groupe
    {
        $filiere = Filiere::create([
            'nom'           => 'FIL MAN ' . $suffix,
            'code'          => 'FM' . substr(md5($suffix . uniqid()), 0, 8),
            'section'       => 'SECTION',
            'nombre_annees' => $nombreAnnees,
        ]);
        $this->createdFilieres[] = $filiere;

        $niveau = Niveau::create([
            'filiere_id' => $filiere->id,
            'numero'     => $numero,
        ]);
        $this->createdNiveaux[] = $niveau;

        $groupe = Groupe::create([
            'niveau_id'           => $niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                => 'GRP MAN ' . $suffix,
            'promotion'          => 'MAN ' . $suffix,
        ]);
        $this->createdGroupes[] = $groupe;

        return $groupe;
    }

    private function makeEtudiant(string $suffix, int $groupeId, string $status = 'active'): Etudiant
    {
        $etudiant = Etudiant::create([
            'user_id'             => $this->admin->id,
            'groupe_id'           => $groupeId,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'MAN STUDENT ' . $suffix,
            'cin'                 => 'CIN' . substr(md5($suffix . uniqid('', true)), 0, 12),
            'numero_inscription'  => 'INS' . substr(md5('num' . $suffix . uniqid('', true)), 0, 14),
            'status'              => $status,
        ]);

        $this->createdEtudiants[] = $etudiant;
        return $etudiant;
    }

    /**
     * No publication, no notes, average 0 -- none of it stops the manual
     * override. The graduating year is not a rule either.
     */
    public function test_manual_generation_forges_a_diploma_without_notes_or_bulletin(): void
    {
        $etudiant = $this->makeEtudiant('FORGE', $this->makeGroupe('FORGE')->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/manual-generate', [
                'etudiant_id'         => $etudiant->id,
                'annee_academique_id' => $this->annee->id,
            ]);

        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertSame('Diplôme généré manuellement', $res->json('message'));

        $this->assertDatabaseHas('diplomes', [
            'etudiant_id'          => $etudiant->id,
            'annee_academique_id'  => $this->annee->id,
            'moyenne_generale'     => 0,
            'mention'              => 'Insuffisant',
        ]);
        $this->assertSame('graduate', $etudiant->refresh()->status);
    }

    public function test_manual_generation_has_no_rules_and_graduates_a_non_final_year_student(): void
    {
        // A two-year filière, student in year 1: the admin can still hand them a
        // diploma by hand -- the app may have failed to load their notes.
        $etudiant = $this->makeEtudiant('EARLY', $this->makeGroupe('EARLY', numero: 1, nombreAnnees: 2)->id);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/diplomes/manual-generate', [
                'etudiant_id'         => $etudiant->id,
                'annee_academique_id' => $this->annee->id,
            ]);

        $res->assertOk();
        $this->assertDatabaseHas('diplomes', [
            'etudiant_id'          => $etudiant->id,
            'annee_academique_id'  => $this->annee->id,
            'moyenne_generale'     => 0,
            'mention'              => 'Insuffisant',
        ]);
        $this->assertSame('graduate', $etudiant->refresh()->status);
    }

    public function test_manual_candidates_list_all_final_year_students_even_with_a_diploma(): void
    {
        $final = $this->makeGroupe('CAND', numero: 1, nombreAnnees: 1);
        $finalStudent = $this->makeEtudiant('CAND OK', $final->id);

        $early = $this->makeGroupe('CAND2', numero: 1, nombreAnnees: 2);
        $earlyStudent = $this->makeEtudiant('CAND EARLY', $early->id);

        $already = $this->makeEtudiant('CAND DONE', $final->id);
        Diplome::create([
            'etudiant_id'          => $already->id,
            'annee_academique_id'  => $this->annee->id,
            'moyenne_generale'     => 14,
            'mention'              => 'Bien',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/diplomes/manual-candidates?annee_academique_id={$this->annee->id}");

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($finalStudent->id, $ids, 'Final-year students must be listed');
        $this->assertNotContains($earlyStudent->id, $ids, 'Only the filière\'s final year can earn a diploma');
        $this->assertContains($already->id, $ids, 'A recorded diploma keeps them ON the list (re-generation overwrites it)');

        $byId = collect($res->json('data'))->keyBy('id');
        $this->assertTrue($byId[$finalStudent->id]['annee_diplomante'], 'Final year is flagged for the admin');
        $this->assertTrue($byId[$already->id]['annee_diplomante'], 'Already-diplomed final-year stays flagged');
    }
}