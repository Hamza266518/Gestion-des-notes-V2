<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Diplome;
use App\Models\DiplomeParametre;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The establishment-level values (opening authorisation, accreditation, the
 * diploma number) are the same for every diploma: they are typed once from the
 * "Informations du diplôme" form, stored in diplome_parametres, and printed on
 * the halves each one declares of every generated .docx. Their marker positions
 * are calibrated per template type and saved from the UI.
 *
 * The settings row is a shared singleton, so it is snapshotted and restored
 * around each test (and rows are deleted by primary key) to keep the shared
 * suite database untouched.
 */
class DiplomeInformationsTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdDiplomes = [];
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];

    private bool $rowExisted = false;
    private ?array $savedValeurs = null;
    private ?array $savedPositions = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.infos@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome infos admin',
                'email'    => 'diplome.infos@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test diplomas');

        // Snapshot the singleton so the shared suite database is left unchanged.
        $this->rowExisted = DiplomeParametre::where('id', 1)->exists();
        if ($this->rowExisted) {
            $row = DiplomeParametre::find(1);
            $this->savedValeurs = $row->valeurs;
            $this->savedPositions = $row->positions;
        }
    }

    protected function tearDown(): void
    {
        if ($this->rowExisted) {
            DiplomeParametre::where('id', 1)->update([
                'valeurs'   => $this->savedValeurs === null ? null : json_encode($this->savedValeurs),
                'positions' => $this->savedPositions === null ? null : json_encode($this->savedPositions),
            ]);
        } else {
            DiplomeParametre::where('id', 1)->delete();
        }

        foreach ($this->createdDiplomes as $diplome) {
            Diplome::where('id', $diplome->id)->delete();
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

    private function makeDiplome(string $typeFormation, string $suffix): Diplome
    {
        $filiere = Filiere::create([
            'nom'            => 'FIL INFOS ' . $suffix,
            'nom_ar'         => 'شعبة ' . $suffix,
            'code'           => 'FI' . substr(md5($typeFormation . $suffix . uniqid()), 0, 8),
            'section'        => 'SECTION',
            'type_formation' => $typeFormation,
            'nombre_annees'  => 1,
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
            'nom'                 => 'GRP INFOS ' . $suffix,
            'promotion'           => '2024/2025',
        ]);
        $this->createdGroupes[] = $groupe;

        $etudiant = Etudiant::create([
            'user_id'             => $this->admin->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'STUDENT INFOS ' . $suffix,
            'nom_ar'              => 'تلميذ',
            'cin'                 => 'INFOS' . substr(md5($suffix . uniqid('', true)), 0, 10),
            'numero_inscription'  => 'INS' . substr(md5('n' . $suffix . uniqid('', true)), 0, 14),
            'lieu_naissance'      => 'Oujda',
            'lieu_naissance_ar'   => 'وجدة',
            'nationalite'         => 'Marocaine',
            'nationalite_ar'      => 'مغربية',
            'status'              => 'graduate',
        ]);
        $this->createdEtudiants[] = $etudiant;

        $diplome = Diplome::create([
            'etudiant_id'         => $etudiant->id,
            'annee_academique_id' => $this->annee->id,
            'moyenne_generale'    => 14.25,
            'mention'             => 'Bien',
            'is_printed'          => false,
        ]);
        $this->createdDiplomes[] = $diplome;

        return $diplome;
    }

    public function test_informations_are_saved_and_read_back(): void
    {
        $valeurs = [
            'info_ouverture_date'    => '2010-03-15',
            'info_ouverture_no'      => 'OUV-11',
            'info_accreditation_no'  => 'ACC-42',
            'info_sous_no'           => 'SN-7',
            'info_accreditation_date' => '2011-09-01',
            'info_pv_date'           => '2011-06-10',
            'info_serie_no'          => 'SN-2024-0001',
            'institut_fr'            => 'Institut Privé de Formation Professionnelle',
            'institut_ar'            => 'معهد خاص للتكوين المهني',
            'ville_fr'               => 'Oujda',
            'ville_ar'               => 'وجدة',
        ];

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', ['valeurs' => $valeurs])
            ->assertOk()
            ->assertJsonPath('success', true);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/diplomes/informations');

        $res->assertOk();
        $this->assertEquals($valeurs, $res->json('data.valeurs'));
    }

    public function test_informations_endpoint_exposes_the_configured_definitions(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/diplomes/informations');

        $res->assertOk();
        $keys = array_column($res->json('data.infos'), 'key');
        $this->assertEquals([
            'info_ouverture_date',
            'info_ouverture_no',
            'info_accreditation_no',
            'info_sous_no',
            'info_accreditation_date',
            'info_pv_date',
            'info_serie_no',
            'institut_fr',
            'institut_ar',
            'ville_fr',
            'ville_ar',
        ], $keys);
    }

    public function test_information_values_are_printed_on_both_halves_of_the_docx(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', [
                'valeurs' => ['info_accreditation_no' => 'SERIAL-XYZ-9999'],
            ])
            ->assertOk();

        $diplome = $this->makeDiplome('Technicien', 'BOTH');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertGreaterThanOrEqual(
                2,
                substr_count($document, 'SERIAL-XYZ-9999'),
                'The value must be printed on the French and the Arabic half'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_a_fact_without_an_arabic_marker_prints_on_the_french_half_only(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', [
                'valeurs' => ['info_serie_no' => 'SERIE-FR-ONLY-123'],
            ])
            ->assertOk();

        $diplome = $this->makeDiplome('Technicien', 'SERIE');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertSame(
                1,
                substr_count($document, 'SERIE-FR-ONLY-123'),
                'A fact with no Arabic marker must be printed on the French half only'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_date_informations_are_stored_as_dates_and_printed_in_french_format(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', [
                'valeurs' => ['info_accreditation_date' => '2012-06-20'],
            ])
            ->assertOk();

        $diplome = $this->makeDiplome('Technicien', 'DATE');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertStringContainsString('20/06/2012', $document);
            $this->assertStringNotContainsString('2012-06-20', $document, 'The ISO value must be formatted for print');
            $this->assertSame(
                1,
                substr_count($document, '20/06/2012'),
                'The accreditation date must be printed on the French half only'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_an_invalid_date_information_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', [
                'valeurs' => ['info_accreditation_date' => 'not-a-date'],
            ])
            ->assertStatus(422);
    }

    public function test_calibrated_positions_are_persisted_and_exposed(): void
    {
        $positions = [
            'Technicien' => [
                'info_accreditation_no' => [
                    'fr' => ['x' => 0.1234, 'y' => 0.5678],
                    'ar' => ['x' => 0.8765, 'y' => 0.4321],
                ],
            ],
        ];

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations/positions', ['positions' => $positions])
            ->assertOk()
            ->assertJsonPath('success', true);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/diplomes/templates-meta');

        $res->assertOk();
        $this->assertEquals(
            0.1234,
            $res->json('data.info_positions.Technicien.info_accreditation_no.fr.x')
        );
        $this->assertEquals(
            0.8765,
            $res->json('data.info_positions.Technicien.info_accreditation_no.ar.x')
        );
    }

    public function test_saving_informations_requires_a_valeurs_array(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/admin/diplomes/informations', [])
            ->assertStatus(422);
    }
}
