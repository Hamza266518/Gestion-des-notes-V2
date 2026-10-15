<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\SoutenanceService;
use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The soutenance scanner reads the jury sheet of a class: one row per student,
 * and on that row the grade of EVERY criterion. Unlike the exam scanner there
 * is no control to pick on screen, so the endpoint is bound to the "Soutenance"
 * unit of the chosen filière/année and refuses any other unit.
 *
 * These tests pin that a scanned row is matched inside the chosen group only,
 * that the grades land on the criteria in order as confirmed Note rows, that an
 * "AB" becomes an absence, and that a misread (14.7) is refused and named back.
 */
class SoutenanceScanTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private Filiere $filiere;
    private Niveau $niveau;
    private Unite $unite;
    private array $controleIds = [];
    private array $tracked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstWhere('role', 'admin')
            ?? User::create([
                'name'     => 'Soutenance admin',
                'email'    => 'soutenance.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
        $this->track('users', $this->admin->id);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->filiere = Filiere::create([
            'nom'            => 'SOTTEST ' . bin2hex(random_bytes(3)),
            'code'           => 'SOT' . bin2hex(random_bytes(3)),
            'section'        => 'Section Soutenance',
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
            'nom'          => SoutenanceService::UNITE_NOM,
            'coefficient'  => 1,
            'numero_annee' => 1,
            'semestre'     => 2,
            'is_active'    => true,
        ]);
        $this->track('unites', $this->unite->id);

        foreach (OfficialCurriculum::NSTI_CRITERIA as $index => $criterion) {
            $controle = Controle::create([
                'sequence_id' => null,
                'unite_id'    => $this->unite->id,
                'numero'      => $index + 1,
                'nom'         => $criterion['nom'],
                'note_max'    => 20,
            ]);
            $this->controleIds[] = $controle->id;
        }
    }

    protected function tearDown(): void
    {
        $etudiantIds = $this->tracked['etudiants'] ?? [];
        $groupeIds   = $this->tracked['groupes'] ?? [];

        \App\Models\Note::whereIn('etudiant_id', $etudiantIds)->delete();
        \App\Models\Note::whereIn('controle_id', $this->controleIds)->delete();

        Etudiant::whereIn('id', $etudiantIds)->forceDelete();

        SemestrePublication::whereIn('groupe_id', $groupeIds)->delete();

        foreach ($groupeIds as $id) {
            \DB::table('groupes')->where('id', $id)->delete();
        }
        \DB::table('controles')->whereIn('id', $this->controleIds)->delete();
        \DB::table('unites')->where('id', $this->unite->id)->delete();

        foreach ($this->tracked['niveaux'] ?? [] as $id) {
            \DB::table('niveaux')->where('id', $id)->delete();
        }
        foreach ($this->tracked['filieres'] ?? [] as $id) {
            \DB::table('filieres')->where('id', $id)->delete();
        }
        foreach ($this->tracked['users'] ?? [] as $id) {
            \DB::table('users')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->tracked[$table][] = $id;
    }

    private function groupe(string $suffix): Groupe
    {
        $groupe = Groupe::create([
            'niveau_id'           => $this->niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'SOT-G' . $suffix,
            'promotion'           => 'SOT',
        ]);
        $this->track('groupes', $groupe->id);
        return $groupe;
    }

    private function etudiantDans(Groupe $groupe, string $nom): Etudiant
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => 'soutenance.' . bin2hex(random_bytes(5)) . '@sweep.test',
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
            'cin'                 => 'SOT' . bin2hex(random_bytes(5)),
            'numero_inscription'  => 'SOT-' . bin2hex(random_bytes(4)),
        ]);
        $this->track('etudiants', $etudiant->id);
        return $etudiant;
    }

    private function mockGemini(string $json): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($json) {
            $mock->shouldReceive('scanSoutenance')->andReturn($json);
        });
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('jury.pdf', 20, 'application/pdf');
    }

    public function test_status_reports_a_programme_with_a_soutenance(): void
    {
        $response = $this->actingAs($this->admin)->getJson(
            '/api/admin/soutenance/status?filiere_id=' . $this->filiere->id . '&niveau_id=' . $this->niveau->id
        );

        $response->assertOk()
            ->assertJsonPath('data.has_soutenance', true)
            ->assertJsonPath('data.unite.nom', SoutenanceService::UNITE_NOM)
            ->assertJsonPath('data.unite.semestre', 2);

        $this->assertCount(count(OfficialCurriculum::NSTI_CRITERIA), $response->json('data.criteres'));
        // The criteria come back numbered 1..N in the jury order.
        $this->assertSame(1, $response->json('data.criteres.0.numero'));
        $this->assertSame(OfficialCurriculum::NSTI_CRITERIA[0]['nom'], $response->json('data.criteres.0.nom'));
    }

    public function test_status_reports_a_programme_without_a_soutenance(): void
    {
        $filiere = Filiere::create([
            'nom'            => 'NOSOT ' . bin2hex(random_bytes(3)),
            'code'           => 'NST' . bin2hex(random_bytes(3)),
            'section'        => 'Section NOSOT',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 3,
        ]);
        $this->track('filieres', $filiere->id);

        $niveau = Niveau::create(['filiere_id' => $filiere->id, 'numero' => 1]);
        $this->track('niveaux', $niveau->id);

        $this->actingAs($this->admin)->getJson(
            '/api/admin/soutenance/status?filiere_id=' . $filiere->id . '&niveau_id=' . $niveau->id
        )
            ->assertOk()
            ->assertJsonPath('data.has_soutenance', false)
            ->assertJsonPath('data.criteres', []);
    }

    public function test_scan_reads_every_criterion_of_a_row_in_order(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SOTTEST AMINE BENALI');

        $this->mockGemini(json_encode([[
            'nom'                => $etudiant->nom_prenom,
            'nom_ar'             => 'أمين بنعلي',
            'numero_inscription' => '',
            'notes'              => [14.5, 12, 13.25],
        ]]));

        $response = $this->actingAs($this->admin)->post('/api/admin/soutenance/scan', [
            'unite_id'            => $this->unite->id,
            'annee_academique_id' => $this->annee->id,
            'filiere_id'          => $this->filiere->id,
            'groupe_id'           => $groupe->id,
            'pdfs'                => [$this->pdf()],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $row = $response->json('data.resultats.0');
        $this->assertTrue($row['found']);
        $this->assertEquals($etudiant->id, $row['etudiant_id']);
        $this->assertCount(count(OfficialCurriculum::NSTI_CRITERIA), $row['notes']);

        // The grades stay aligned to the criteria, in jury order.
        $this->assertEquals($this->controleIds[0], $row['notes'][0]['controle_id']);
        $this->assertEquals(14.5, $row['notes'][0]['valeur']);
        $this->assertEquals(12, $row['notes'][1]['valeur']);
        $this->assertEquals(13.25, $row['notes'][2]['valeur']);
        // A criterion the sheet left blank is reported as ungraded, never a 0.
        $this->assertTrue($row['notes'][3]['invalid']);
        $this->assertNull($row['notes'][3]['valeur']);
    }

    public function test_scan_reports_a_misread_without_snapping_it(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SOTTEST SALMA ALAOUI');

        // 14.7 is a misread of the sheet, not a grade: it comes back flagged with
        // the raw text so the operator can correct it, never rounded to 14.75.
        $this->mockGemini(json_encode([[
            'nom'                => $etudiant->nom_prenom,
            'nom_ar'             => '',
            'numero_inscription' => '',
            'notes'              => [14.7],
        ]]));

        $response = $this->actingAs($this->admin)->post('/api/admin/soutenance/scan', [
            'unite_id'            => $this->unite->id,
            'annee_academique_id' => $this->annee->id,
            'filiere_id'          => $this->filiere->id,
            'groupe_id'           => $groupe->id,
            'pdfs'                => [$this->pdf()],
        ]);

        $cell = $response->json('data.resultats.0.notes.0');
        $this->assertTrue($cell['invalid']);
        $this->assertNull($cell['valeur']);
        $this->assertEquals('14.7', $cell['raw']);
    }

    public function test_scan_refuses_a_unit_that_is_not_a_soutenance(): void
    {
        $autre = Unite::create([
            'filiere_id'   => $this->filiere->id,
            'nom'          => 'MATH ' . bin2hex(random_bytes(3)),
            'coefficient'  => 1,
            'numero_annee' => 1,
            'semestre'     => 2,
            'is_active'    => true,
        ]);

        $response = $this->actingAs($this->admin)->post('/api/admin/soutenance/scan', [
            'unite_id'            => $autre->id,
            'annee_academique_id' => $this->annee->id,
            'filiere_id'          => $this->filiere->id,
            'pdfs'                => [$this->pdf()],
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);

        \DB::table('unites')->where('id', $autre->id)->delete();
    }

    public function test_confirm_writes_confirmed_notes_on_the_criteria(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SOTTEST OMAR ZIANI');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/soutenance/confirm', [
                'unite_id'            => $this->unite->id,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
                'notes'               => [
                    ['etudiant_id' => $etudiant->id, 'controle_id' => $this->controleIds[0], 'valeur' => '14.5'],
                    ['etudiant_id' => $etudiant->id, 'controle_id' => $this->controleIds[1], 'valeur' => 'AB'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('notes', [
            'etudiant_id'  => $etudiant->id,
            'controle_id'  => $this->controleIds[0],
            'valeur'       => 14.5,
            'est_absent'   => false,
            'is_confirmed' => true,
        ]);

        // "AB" is stored as an absence with valeur 0, exactly like the exam scan.
        $absent = \App\Models\Note::where('etudiant_id', $etudiant->id)
            ->where('controle_id', $this->controleIds[1])
            ->first();
        $this->assertNotNull($absente = $absent);
        $this->assertTrue((bool) $absent->est_absent);
        $this->assertEquals(0, $absent->valeur);
        $this->assertTrue((bool) $absent->is_confirmed);
    }

    public function test_confirm_skips_a_misread_and_reports_it(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SOTTEST NADIA FASSI');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/soutenance/confirm', [
                'unite_id'            => $this->unite->id,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
                'notes'               => [
                    ['etudiant_id' => $etudiant->id, 'controle_id' => $this->controleIds[0], 'valeur' => '14.7'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('skipped.0.etudiant_id', $etudiant->id);

        $this->assertDatabaseMissing('notes', [
            'etudiant_id' => $etudiant->id,
            'controle_id' => $this->controleIds[0],
        ]);
    }

    public function test_confirm_skips_a_student_outside_the_chosen_group(): void
    {
        $choisi = $this->groupe('1');
        $voisin = $this->groupe('2');
        $etranger = $this->etudiantDans($voisin, 'SOTTEST NADIA EL FASSI');

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/soutenance/confirm', [
                'unite_id'            => $this->unite->id,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $choisi->id,
                'notes'               => [[
                    'etudiant_id' => $etranger->id,
                    'controle_id' => $this->controleIds[0],
                    'valeur'      => '15',
                ]],
            ]);

        $response->assertOk();
        $this->assertSame(
            0,
            \App\Models\Note::where('etudiant_id', $etranger->id)
                ->where('controle_id', $this->controleIds[0])
                ->count(),
            'A student outside the chosen group must not receive the grade.'
        );
        $this->assertTrue(
            collect($response->json('skipped'))->contains('etudiant_id', $etranger->id)
        );
    }

    public function test_confirm_is_refused_when_the_group_is_published(): void
    {
        $groupe = $this->groupe('1');
        $etudiant = $this->etudiantDans($groupe, 'SOTTEST RACHID AMRANI');

        SemestrePublication::create([
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'type'                => 'notes_s2',
            'is_published'        => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/soutenance/confirm', [
                'unite_id'            => $this->unite->id,
                'annee_academique_id' => $this->annee->id,
                'groupe_id'           => $groupe->id,
                'notes'               => [[
                    'etudiant_id' => $etudiant->id,
                    'controle_id' => $this->controleIds[0],
                    'valeur'      => '15',
                ]],
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('notes', [
            'etudiant_id' => $etudiant->id,
            'controle_id' => $this->controleIds[0],
        ]);
    }
}
