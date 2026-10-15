<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Unite;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The exam scanner now reads sheets by NAME against the list of the chosen
 * groupe, exactly like the contrôle scanner: no anonymous codes, no key sheet.
 *
 * These tests pin that the name is only ever matched inside that list (or the
 * unite's filière/année when no group was picked), that the manual search
 * respects the same group barrier, and that confirm re-checks every linked row
 * against the cohort the sheet was scanned for.
 */
class ScanExamenTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private Filiere $filiere;
    private Niveau $niveau;
    private Unite $unite;
    private array $tracked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstWhere('role', 'admin')
            ?? User::create([
                'name'     => 'Scan examen admin',
                'email'    => 'scan.examen.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
        $this->track('users', $this->admin->id);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        // A private filière/niveau/unite, so these tests never depend on the
        // seeded programmes nor collide with them.
        $this->filiere = Filiere::create([
            'nom'            => 'SCANEX ' . bin2hex(random_bytes(3)),
            'code'           => 'SCX' . bin2hex(random_bytes(3)),
            'section'        => 'Section SCANEX',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 3,
        ]);
        $this->track('filieres', $this->filiere->id);

        $this->niveau = Niveau::create([
            'filiere_id' => $this->filiere->id,
            'numero'     => 1,
        ]);
        $this->track('niveaux', $this->niveau->id);

        $this->unite = Unite::create([
            'filiere_id'   => $this->filiere->id,
            'nom'          => 'SCANEX UNITE ' . bin2hex(random_bytes(3)),
            'coefficient'  => 2,
            'numero_annee' => 1,
            'semestre'     => 1,
            'is_active'    => true,
        ]);
        $this->track('unites', $this->unite->id);
    }

    protected function tearDown(): void
    {
        // Dependency order: children before parents so no FK blocks the delete.
        $etudiantIds = $this->tracked['etudiants'] ?? [];
        if ($etudiantIds) {
            \DB::table('examens')->whereIn('etudiant_id', $etudiantIds)->delete();
        }
        foreach ($etudiantIds as $id) {
            Etudiant::whereKey($id)->forceDelete();
        }
        foreach ($this->tracked['groupes'] ?? [] as $id) {
            \DB::table('groupes')->where('id', $id)->delete();
        }
        foreach ($this->tracked['unites'] ?? [] as $id) {
            \DB::table('unites')->where('id', $id)->delete();
        }
        foreach ($this->tracked['niveaux'] ?? [] as $id) {
            \DB::table('niveaux')->where('id', $id)->delete();
        }
        foreach ($this->tracked['filieres'] ?? [] as $id) {
            \DB::table('filieres')->where('id', $id)->delete();
        }
        foreach ($this->tracked['users'] ?? [] as $id) {
            \DB::table('users')->where('id', $id)->delete();
        }
        foreach ($this->tracked['annees_academiques'] ?? [] as $id) {
            \DB::table('annees_academiques')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->tracked[$table][] = $id;
    }

    /** A group of the fixture filière/année, tracked for teardown. */
    private function groupe(string $suffix): Groupe
    {
        $groupe = Groupe::create([
            'niveau_id'           => $this->niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'SCANEX-G' . $suffix,
            'promotion'           => 'SCANEX',
        ]);
        $this->track('groupes', $groupe->id);
        return $groupe;
    }

    private function etudiantDans(Groupe $groupe, string $nom): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'scan.examen.' . bin2hex(random_bytes(5)) . '@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->track('users', $user->id);

        $etudiant = Etudiant::create([
            'user_id'             => $user->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'status'              => 'active',
            'nom_prenom'          => $nom,
            'cin'                 => 'SEX' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'SEX' . bin2hex(random_bytes(4)),
        ]);
        $this->track('etudiants', $etudiant->id);
        return $etudiant;
    }

    private function mockGemini(string $json): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($json) {
            $mock->shouldReceive('scanExamens')->andReturn($json);
        });
    }

    /** One fake PDF, enough to satisfy validation: Gemini itself is mocked. */
    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('sheet.pdf', 20, 'application/pdf');
    }

    private function scanUrl(): string
    {
        return '/api/admin/scan-examens';
    }

    public function test_a_name_from_the_chosen_group_is_matched(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SCANTEST AHMED ALAMI');

        $this->mockGemini(json_encode([[
            'nom'                 => $etudiant->nom_prenom,
            'nom_ar'              => '',
            'numero_inscription'  => '',
            'note'                => 14.5,
        ]]));

        $response = $this->actingAs($this->admin)
            ->post($this->scanUrl(), [
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'groupe_id'           => $groupe->id,
                'pdfs'                => [$this->pdf()],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $result = $response->json('data.resultats.0');
        $this->assertNotNull($result);
        $this->assertTrue($result['found']);
        $this->assertEquals($etudiant->id, $result['etudiant_id']);
        $this->assertEquals($etudiant->nom_prenom, $result['nom_prenom']);
        $this->assertEquals(14.5, $result['note']);
        $this->assertEquals($groupe->nom, $result['groupe']);
    }

    public function test_a_name_from_a_sibling_group_is_not_matched_when_one_group_is_chosen(): void
    {
        $choisi = $this->groupe('1');
        $voisin = $this->groupe('2');
        $voisineEtudiant = $this->etudiantDans($voisin, 'SCANTEST AMINA BENJELLOUN');

        $this->mockGemini(json_encode([[
            'nom'                => $voisineEtudiant->nom_prenom,
            'nom_ar'             => '',
            'numero_inscription' => '',
            'note'               => 12,
        ]]));

        $response = $this->actingAs($this->admin)
            ->post($this->scanUrl(), [
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'groupe_id'           => $choisi->id,
                'pdfs'                => [$this->pdf()],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $result = $response->json('data.resultats.0');
        $this->assertNotNull($result);
        $this->assertFalse($result['found']);
        $this->assertNull($result['etudiant_id']);
        // The scanned name is echoed back so the admin can see who was not found.
        $this->assertEquals($voisineEtudiant->nom_prenom, $result['nom_prenom']);
    }

    public function test_without_a_group_the_whole_filiere_annee_is_the_perimeter(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SCANTEST KARIM TAZI');

        $this->mockGemini(json_encode([[
            'nom'                => $etudiant->nom_prenom,
            'nom_ar'             => '',
            'numero_inscription' => '',
            'note'               => 9.75,
        ]]));

        $response = $this->actingAs($this->admin)
            ->post($this->scanUrl(), [
                'unite_id'            => $this->unite->id,
                'bloc'                => 2,
                'type'                => 'pratique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'pdfs'                => [$this->pdf()],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.resultats.0.found', true);
    }

    public function test_a_group_outside_the_unite_perimeter_is_rejected(): void
    {
        $this->mockGemini(json_encode([[
            'nom'                => 'SCANTEST QUI CONQUE',
            'nom_ar'             => '',
            'numero_inscription' => '',
            'note'               => 10,
        ]]));

        // No groupe matches the unite's filière/année: the list is empty and the
        // scan must not proceed, instead of silently matching nobody.
        $response = $this->actingAs($this->admin)
            ->post($this->scanUrl(), [
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'groupe_id'           => 99999999,
                'pdfs'                => [$this->pdf()],
            ]);

        $response->assertJsonPath('success', false);
    }

    public function test_search_respects_the_chosen_group(): void
    {
        $choisi = $this->groupe('1');
        $voisin = $this->groupe('2');
        $dansLeChoisi = $this->etudiantDans($choisi, 'SCANTEST NADIA CHRAIBI');
        $horsListe = $this->etudiantDans($voisin, 'SCANTEST SALMA CHRAIBI');

        $response = $this->actingAs($this->admin)
            ->json('GET', '/api/admin/scan-examens/search-etudiants', [
                'search'              => 'chraibi',
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'niveau_numero'       => 1,
                'semestre'            => 1,
                'groupe_id'           => $choisi->id,
            ]);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($dansLeChoisi->id, $ids, 'The row of the chosen group must be suggested.');
        $this->assertNotContains($horsListe->id, $ids, 'A sibling group student must never be suggested for a hand-linked row.');
    }

    public function test_confirm_refuses_a_row_linked_outside_the_chosen_group(): void
    {
        $groupe = $this->groupe('1');
        $horsListe = $this->etudiantDans($groupe, 'SCANTEST YASSINE IDRISSI');
        // A student of another group of the same filière/année.
        $autreGroupe = $this->groupe('2');
        $etranger = $this->etudiantDans($autreGroupe, 'SCANTEST OUMAIMA EL FASSI');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etranger->id,
                    'note'        => 15,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etranger->id,
            'unite_id'    => $this->unite->id,
        ]);

        // A student of the pinned group is refused the same way when the scanned
        // year does not match theirs.
        $autreAnnee = AnneeAcademique::create([
            'label'      => 'SCANEX ' . bin2hex(random_bytes(3)),
            'start_date' => $this->annee->start_date,
            'end_date'   => $this->annee->end_date,
            'is_current' => false,
            'is_archived'=> false,
        ]);
        $this->track('annees_academiques', $autreAnnee->id);

        $etrangerAnnee = $this->etudiantDans($autreGroupe, 'SCANTEST RACHIDA ALAMI');
        $etrangerAnnee->update(['annee_academique_id' => $autreAnnee->id]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etrangerAnnee->id,
                    'note'        => 15,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $autreGroupe->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etrangerAnnee->id,
            'unite_id'    => $this->unite->id,
        ]);
    }

    public function test_full_scan_then_confirm_of_a_scanned_groupe(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SCANTEST MOHAMED AMRANI');

        $this->mockGemini(json_encode([[
            'nom'                => $etudiant->nom_prenom,
            'nom_ar'             => '',
            'numero_inscription' => '',
            'note'               => 16,
        ]]));

        $scan = $this->actingAs($this->admin)
            ->post($this->scanUrl(), [
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'filiere_id'          => $this->filiere->id,
                'groupe_id'           => $groupe->id,
                'pdfs'                => [$this->pdf()],
            ]);

        $scan->assertJsonPath('data.resultats.0.etudiant_id', $etudiant->id);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etudiant->id,
                    'note'        => 16,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $this->unite->id,
            'valeur'      => 16,
        ]);
    }

    public function test_confirm_refuses_a_non_quarter_grade_and_reports_it(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SCANTEST YOUSSEF BENNANI');

        // 14.7 is a misread of the sheet, not a grade: it must be refused and the
        // row named back to the operator instead of being rounded or dropped in
        // silence.
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-examens/confirm', [
                'resultats' => [[
                    'etudiant_id' => $etudiant->id,
                    'note'        => 14.7,
                    'est_absent'  => false,
                ]],
                'unite_id'            => $this->unite->id,
                'bloc'                => 1,
                'type'                => 'theorique',
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped.0.etudiant_id', $etudiant->id);

        $this->assertStringContainsString('0,25', (string) $response->json('skipped.0.raison'));

        $this->assertDatabaseMissing('examens', [
            'etudiant_id' => $etudiant->id,
            'unite_id'    => $this->unite->id,
        ]);
    }
}