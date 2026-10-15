<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The CIN scanner can be run in "corriger" (sync) mode against a whole group:
 * every scanned CIN is matched to an existing student by CIN, the differences
 * are shown side-by-side, and only corrections with a non-empty readable value
 * are applied — identity (CIN/numero/annee/groupe) is never rewritten.
 */
class ScanCinRescanTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private Filiere $filiere;
    private Niveau $niveauFinal;
    private Groupe $groupeFinal;
    private array $tracked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstWhere('role', 'admin')
            ?? User::create([
                'name'     => 'Scan CIN rescan admin',
                'email'    => 'scancin.rescan.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
        $this->track('users', $this->admin->id);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->filiere = Filiere::create([
            'nom'            => 'RESCAN ' . bin2hex(random_bytes(3)),
            'code'           => 'RES' . bin2hex(random_bytes(3)),
            'section'        => 'Section RESCAN',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 3,
        ]);
        $this->track('filieres', $this->filiere->id);

        $this->niveauFinal = Niveau::create([
            'filiere_id' => $this->filiere->id,
            'numero'     => 3,
        ]);
        $this->track('niveaux', $this->niveauFinal->id);

        $this->groupeFinal = Groupe::create([
            'niveau_id'           => $this->niveauFinal->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'RESCAN-G3',
            'promotion'           => 'RESCAN',
        ]);
        $this->track('groupes', $this->groupeFinal->id);
    }

    protected function tearDown(): void
    {
        $etudiantIds = $this->tracked['etudiants'] ?? [];
        foreach ($etudiantIds as $id) {
            Etudiant::whereKey($id)->forceDelete();
        }
        foreach ($this->tracked['users'] ?? [] as $id) {
            \DB::table('users')->where('id', $id)->delete();
        }
        foreach ($this->tracked['groupes'] ?? [] as $id) {
            \DB::table('groupes')->where('id', $id)->delete();
        }
        foreach ($this->tracked['niveaux'] ?? [] as $id) {
            \DB::table('niveaux')->where('id', $id)->delete();
        }
        foreach ($this->tracked['filieres'] ?? [] as $id) {
            \DB::table('filieres')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->tracked[$table][] = $id;
    }

    private function creerEtudiant(string $cin, array $extra = []): Etudiant
    {
        $user = User::create([
            'name'     => $extra['nom_prenom'] ?? 'RESCAN STAGIAIRE',
            'email'    => 'rescan.' . bin2hex(random_bytes(5)) . '@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->track('users', $user->id);

        $etudiant = Etudiant::create(array_merge([
            'user_id'             => $user->id,
            'groupe_id'           => $this->groupeFinal->id,
            'annee_academique_id' => $this->annee->id,
            'status'              => 'active',
            'nom_prenom'          => 'RESCAN STAGIAIRE',
            'cin'                 => $cin,
            'numero_inscription'  => 'RES' . bin2hex(random_bytes(3)),
        ], $extra));
        $this->track('etudiants', $etudiant->id);
        return $etudiant;
    }

    private function mockGemini(string $json): void
    {
        $this->mock(GeminiService::class, function ($mock) use ($json) {
            $mock->shouldReceive('scanCin')->andReturn($json);
        });
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->create('cin.pdf', 20, 'application/pdf');
    }

    public function test_sync_mode_returns_the_full_existing_record_and_keeps_the_numero(): void
    {
        $cin = 'SY' . strtoupper(bin2hex(random_bytes(4)));
        $etudiant = $this->creerEtudiant($cin, [
            'nom_prenom'         => 'EXISTANTE FATIMA',
            'numero_inscription' => '2025/9001',
            'lieu_naissance'     => 'RABAT',
            'nom_ar'             => 'فاطمة',
        ]);

        $this->mockGemini(json_encode([
            [
                'nom_prenom'       => 'FATIMA ZAHRA IDRISSI',
                'nom_ar'           => '',
                'cin'              => $cin,
                'date_naissance'   => '1998-05-15',
                'lieu_naissance'   => '',
                'nationalite'      => 'MAROCAINE',
                'nationalite_ar'   => '',
            ],
            [
                'nom_prenom'       => 'CARD NEVER IN BASE',
                'nom_ar'           => '',
                'cin'              => 'ZZNOPE',
                'date_naissance'   => '',
                'lieu_naissance'   => '',
                'nationalite'      => 'MAROCAINE',
            ],
        ]));

        $response = $this->actingAs($this->admin)
            ->post('/api/admin/scan-cin', [
                'groupe_id'           => $this->groupeFinal->id,
                'annee_academique_id' => $this->annee->id,
                'filiere_code'        => $this->filiere->code,
                'mode'                => 'sync',
                'pdfs'                => [$this->pdf()],
            ])
            ->assertOk();

        $resultats = $response->json('data.resultats');

        $this->assertTrue($resultats[0]['found']);
        $this->assertSame($etudiant->id, $resultats[0]['etudiant_id']);
        $this->assertSame('EXISTANTE FATIMA', $resultats[0]['existing_data']['nom_prenom']);
        $this->assertSame('RABAT', $resultats[0]['existing_data']['lieu_naissance']);
        $this->assertSame('فاطمة', $resultats[0]['existing_data']['nom_ar']);
        $this->assertSame('2025/9001', $resultats[0]['numero_inscription'], 'A re-scan must never renumber an existing student.');

        $this->assertFalse($resultats[1]['found']);
        $this->assertNull($resultats[1]['etudiant_id']);
        $this->assertNull($resultats[1]['existing_data']);
        $this->assertNull($resultats[1]['numero_inscription'], 'An unmatched CIN has nothing to apply.');
    }

    public function test_create_mode_still_reuses_the_existing_numero_for_a_known_cin(): void
    {
        $cin = 'CR' . strtoupper(bin2hex(random_bytes(4)));
        $etudiant = $this->creerEtudiant($cin, [
            'numero_inscription' => '2025/4242',
        ]);

        $this->mockGemini(json_encode([[
            'nom_prenom'       => 'DUPLICATE CARD',
            'nom_ar'           => '',
            'cin'              => $cin,
            'nationalite'      => 'MAROCAINE',
        ]]));

        $response = $this->actingAs($this->admin)
            ->post('/api/admin/scan-cin', [
                'groupe_id'           => $this->groupeFinal->id,
                'annee_academique_id' => $this->annee->id,
                'filiere_code'        => $this->filiere->code,
                'pdfs'                => [$this->pdf()],
            ])
            ->assertOk();

        $resultat = $response->json('data.resultats.0');
        $this->assertTrue($resultat['existing']);
        $this->assertSame($etudiant->id, $resultat['etudiant_id']);
        $this->assertSame('2025/4242', $resultat['numero_inscription']);
    }

    public function test_apply_fills_missing_fields(): void
    {
        $etudiant = $this->creerEtudiant('AP' . strtoupper(bin2hex(random_bytes(4))), [
            'nom_prenom'      => 'ANCIEN NOM',
            'lieu_naissance'  => null,
            'nom_ar'          => null,
            'date_naissance'  => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-cin/apply', [
                'stagiaires' => [[
                    'etudiant_id'    => $etudiant->id,
                    'cin'            => $etudiant->cin,
                    'nom_prenom'     => 'nom corrige',
                    'nom_ar'         => 'الاسم المصحح',
                    'date_naissance' => '1998-05-15',
                    'lieu_naissance' => 'CASABLANCA',
                    'nationalite'    => 'MAROCAINE',
                    'nationalite_ar' => 'مغربية',
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('data.maj'));

        $etudiant->refresh();
        $this->assertSame('NOM CORRIGE', $etudiant->nom_prenom);
        $this->assertSame('الاسم المصحح', $etudiant->nom_ar);
        $this->assertSame('1998-05-15', $etudiant->date_naissance);
        $this->assertSame('CASABLANCA', $etudiant->lieu_naissance);
        $this->assertSame('MAROCAINE', $etudiant->nationalite);
        $this->assertSame('مغربية', $etudiant->nationalite_ar);
        $this->assertSame(strtoupper($etudiant->cin), $etudiant->cin_ar);
        $this->assertSame('NOM CORRIGE', $etudiant->user->fresh()->name);
    }

    public function test_apply_keeps_existing_values_when_the_ocr_field_is_empty(): void
    {
        $etudiant = $this->creerEtudiant('KE' . strtoupper(bin2hex(random_bytes(4))), [
            'nom_prenom'      => 'GARDE MOI',
            'lieu_naissance'  => 'RABAT',
            'nom_ar'          => 'شيء',
            'date_naissance'  => '1990-01-01',
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-cin/apply', [
                'stagiaires' => [[
                    'etudiant_id'    => $etudiant->id,
                    'cin'            => $etudiant->cin,
                    'nom_prenom'     => 'NOUVEAU NOM',
                    'nom_ar'         => '',
                    'date_naissance' => null,
                    'lieu_naissance' => '',
                    'nationalite'    => '',
                ]],
            ])
            ->assertOk();

        $etudiant->refresh();
        $this->assertSame('NOUVEAU NOM', $etudiant->nom_prenom);
        $this->assertSame('شيء', $etudiant->nom_ar, 'An empty OCR value must keep the existing Arabic name.');
        $this->assertSame('RABAT', $etudiant->lieu_naissance, 'An empty OCR value must keep the existing place of birth.');
        $this->assertSame('1990-01-01', $etudiant->date_naissance, 'An unreadable date must not erase the existing one.');
    }

    public function test_apply_refuses_a_different_cin(): void
    {
        $etudiant = $this->creerEtudiant('DI' . strtoupper(bin2hex(random_bytes(4))), [
            'nom_prenom' => 'IDENTITE STABLE',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-cin/apply', [
                'stagiaires' => [[
                    'etudiant_id' => $etudiant->id,
                    'cin'         => 'XY999888',
                    'nom_prenom'  => 'PAS APPLIQUE',
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('data.cin_diff'));
        $this->assertSame(0, $response->json('data.maj'));

        $etudiant->refresh();
        $this->assertSame('IDENTITE STABLE', $etudiant->nom_prenom);
        $this->assertNotSame('XY999888', $etudiant->cin);
    }

    public function test_apply_never_touches_numero_groupe_annee_or_status(): void
    {
        $etudiant = $this->creerEtudiant('PR' . strtoupper(bin2hex(random_bytes(4))), [
            'numero_inscription' => '2025/7777',
            'date_inscription'   => '2025-09-15',
            'status'             => 'graduate',
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-cin/apply', [
                'stagiaires' => [[
                    'etudiant_id'    => $etudiant->id,
                    'cin'            => $etudiant->cin,
                    'nom_prenom'     => strtoupper($etudiant->nom_prenom),
                    'lieu_naissance' => 'FES',
                ]],
            ])
            ->assertOk();

        $etudiant->refresh();
        $this->assertSame('2025/7777', $etudiant->numero_inscription);
        $this->assertSame('2025-09-15', $etudiant->date_inscription);
        $this->assertSame($this->groupeFinal->id, $etudiant->groupe_id);
        $this->assertSame($this->annee->id, $etudiant->annee_academique_id);
        $this->assertSame('graduate', $etudiant->status);
        $this->assertSame('FES', $etudiant->lieu_naissance);
    }

    public function test_apply_counts_an_unknown_etudiant_as_introuvable(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/scan-cin/apply', [
                'stagiaires' => [[
                    'etudiant_id' => 999999999,
                    'cin'         => 'ZZ999',
                    'nom_prenom'  => 'FANTOME',
                ]],
            ])
            ->assertOk();

        $this->assertSame(1, $response->json('data.introuvables'));
        $this->assertSame(0, $response->json('data.maj'));
    }
}