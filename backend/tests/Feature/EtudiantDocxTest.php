<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EtudiantController;
use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use App\Services\EtudiantDocxService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Étudiants" page downloads two editable .docx sheets of its current
 * selection: the "Liste stagiaire" sheet (identification block + numbered
 * student list, shaped after the reference file) and the "Identifiants et mots
 * de passe" sheet the accounts are handed out from. These tests pin that
 * contract: the download endpoints and their headers, the empty-selection
 * refusal, and the layout pieces the reference demands (one A4-portrait page per
 * group, one numbering definition per group so each list restarts at 1, the
 * institute header/footer, and run text that stays XML-safe).
 *
 * Cleanup deletes by primary key, hardened for the shared suite database like
 * the other docx tests.
 */
class EtudiantDocxTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdUsers = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];
    private ?AnneeAcademique $createdAnnee = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'etudiant.docx@test.local')->first()
            ?? User::create([
                'name'     => 'Etudiant docx admin',
                'email'    => 'etudiant.docx@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::where('is_current', false)->first()
            ?? ($this->createdAnnee = AnneeAcademique::create(['label' => 'ETU TEST ' . Str::random(6), 'is_current' => true]));

        $this->assertNotNull($this->annee, 'An academic year is required to test student docs');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdEtudiants as $etudiant) {
            Etudiant::where('id', $etudiant->id)->delete();
        }
        foreach ($this->createdUsers as $user) {
            User::where('id', $user->id)->delete();
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
        if ($this->createdAnnee) {
            AnneeAcademique::where('id', $this->createdAnnee->id)->delete();
        }

        parent::tearDown();
    }

    private function makeFiliere(string $suffix): Filiere
    {
        $filiere = Filiere::create([
            'nom'            => 'INF ' . $suffix,
            'nom_ar'         => 'تمريض',
            'code'           => 'ED' . substr(md5($suffix . uniqid()), 0, 8),
            'section'        => 'Technicien Spécialisé',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 3,
        ]);
        $this->createdFilieres[] = $filiere;

        return $filiere;
    }

    private function makeGroup(Filiere $filiere, int $numero, string $nom, string $promotion): Groupe
    {
        $niveau = Niveau::create([
            'filiere_id' => $filiere->id,
            'numero'     => $numero,
        ]);
        $this->createdNiveaux[] = $niveau;

        $groupe = Groupe::create([
            'niveau_id'           => $niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => $nom,
            'promotion'           => $promotion,
        ]);
        $this->createdGroupes[] = $groupe;

        return $groupe;
    }

    private function makeEtudiant(Groupe $groupe, string $nomPrenom, string $cin, string $numero, string $password = 'Ab1&R8x'): Etudiant
    {
        $user = User::create([
            'name'               => $nomPrenom,
            'username'           => 'etu' . Str::lower(Str::random(10)),
            'email'              => Str::lower(Str::random(12)) . '@etudiant.docx.test',
            'password'           => Hash::make('secret123'),
            'password_encrypted' => Crypt::encryptString($password),
            'role'               => 'etudiant',
        ]);
        $this->createdUsers[] = $user;

        $etudiant = Etudiant::create([
            'user_id'             => $user->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => $nomPrenom,
            'nom_ar'              => 'تلميذ',
            'cin'                 => $cin,
            'numero_inscription'  => $numero,
            'lieu_naissance'      => 'Oujda',
            'nationalite'         => 'Marocaine',
            'status'              => 'active',
        ]);
        $this->createdEtudiants[] = $etudiant;

        return $etudiant;
    }

    /**
     * The datasets buildListe() consumes, read through the controller's own
     * grouping so the test exercises the real ordering (level, then group name).
     */
    private function groupDatasets(Filiere $filiere): array
    {
        $etudiants = Etudiant::with(['user', 'groupe.niveau.filiere', 'anneeAcademique'])
            ->whereHas('groupe.niveau', fn ($q) => $q->where('filiere_id', $filiere->id))
            ->orderBy('nom_prenom')
            ->get();

        $controller = app(EtudiantController::class);
        $method = new \ReflectionMethod($controller, 'groupDatasets');

        return $method->invoke($controller, $etudiants);
    }

    private function documentXml(string $path): string
    {
        \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
        $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
        $zip->open($path);
        $document = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $document;
    }

    private function partXml(string $path, string $part): string
    {
        \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
        $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName($part);
        $zip->close();

        return $content;
    }

    public function test_liste_endpoint_serves_the_docx_with_download_headers(): void
    {
        $filiere = $this->makeFiliere('LISTE');
        $groupe = $this->makeGroup($filiere, 1, 'Groupe A', '2024/2025');
        $this->makeEtudiant($groupe, 'AMARA Samira', 'FA123456', '1 IP25');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/etudiants/docx/liste?filiere_id={$filiere->id}");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );

        $disposition = urldecode((string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('Liste stagiaire', $disposition);
        $this->assertStringContainsString('INF LISTE', $disposition);
        $this->assertStringContainsString('.docx', $disposition);
    }

    public function test_identifiants_endpoint_serves_the_docx_with_download_headers(): void
    {
        $filiere = $this->makeFiliere('CRED');
        $groupe = $this->makeGroup($filiere, 1, 'Groupe A', '2024/2025');
        $this->makeEtudiant($groupe, 'AMARA Samira', 'FA123456', '1 IP25');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/etudiants/docx/identifiants?filiere_id={$filiere->id}");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );

        $disposition = urldecode((string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('Identifiants et mots de passe', $disposition);
        $this->assertStringContainsString('.docx', $disposition);
    }

    public function test_both_downloads_refuse_an_empty_selection(): void
    {
        // A filière with no student: the page's own filter, so the download has
        // nothing to report and must say so instead of serving an empty sheet.
        $filiere = $this->makeFiliere('EMPTY');

        foreach (['liste', 'identifiants'] as $kind) {
            $res = $this->actingAs($this->admin, 'sanctum')
                ->getJson("/api/admin/etudiants/docx/{$kind}?filiere_id={$filiere->id}");

            $res->assertStatus(422);
            $this->assertStringContainsString('Aucun', (string) $res->json('message'));
        }
    }

    /**
     * One identification block and one list per group, each group on its own
     * page, ordered by level: the block's filière / section / promotion must
     * always describe the rows printed under it.
     */
    public function test_liste_builds_one_page_and_one_numbering_per_group_in_level_order(): void
    {
        $filiere = $this->makeFiliere('GROUPS');

        // Created out of order to prove the sort is by level, not insertion.
        $third = $this->makeGroup($filiere, 3, 'Groupe C', '2023/2026');
        $first = $this->makeGroup($filiere, 1, 'Groupe A', '2025/2028');
        $this->makeEtudiant($third, 'ZAHRAOUI Nadia', 'FA300000', '1 IP26');
        $this->makeEtudiant($third, 'ALAOUI Omar', 'FA300001', '2 IP26');
        $this->makeEtudiant($first, 'BENANI Sara', 'FA100000', '1 IP24');

        $datasets = $this->groupDatasets($filiere);
        $this->assertCount(2, $datasets, 'One dataset per group');
        $this->assertSame('1ère année', $datasets[0]['niveau'], 'Groups are ordered by level');
        $this->assertSame('3ème année', $datasets[1]['niveau']);

        $path = app(EtudiantDocxService::class)->buildListe($datasets);

        try {
            $this->assertStringStartsWith('PK', (string) file_get_contents($path), 'A .docx must be a valid (zip) package');

            $document = $this->documentXml($path);
            $numbering = $this->partXml($path, 'word/numbering.xml');

            $this->assertSame(2, substr_count($document, 'Année de formation'), 'One identification block per group');
            $this->assertSame(2, substr_count($document, '<w:sectPr'), 'Each group starts on its own page');
            $this->assertSame(
                2,
                substr_count($numbering, '<w:abstractNum '),
                'Each group owns a numbering definition, so every list restarts at 1'
            );
            $this->assertSame(3, substr_count($document, '<w:numPr>'), 'Every student row is numbered');

            foreach (['BENANI Sara', 'ALAOUI Omar', 'ZAHRAOUI Nadia'] as $name) {
                $this->assertStringContainsString($name, $document);
            }

            // Every name lines up at the reference 449-twip indent, with the
            // list number hanging in front of it.
            $this->assertSame(3, substr_count($document, 'w:hanging="449"'));
        } finally {
            @unlink($path);
        }
    }

    public function test_liste_rows_are_ordered_by_numero_inscription(): void
    {
        $filiere = $this->makeFiliere('ORDER');
        $groupe = $this->makeGroup($filiere, 1, 'Groupe A', '2024/2025');

        // Same group, deliberately out of order: the reference lists them by
        // N° d'inscription, so "2 IP25" must not print before "10 IP25".
        foreach ([['TROIS', '3 IP25'], ['DIX', '10 IP25'], ['DEUX', '2 IP25']] as $i => [$nom, $numero]) {
            $this->makeEtudiant($groupe, $nom . ' Etudiant', 'FA90000' . $i, $numero);
        }

        $datasets = $this->groupDatasets($filiere);

        $this->assertSame(
            ['2 IP25', '3 IP25', '10 IP25'],
            array_column($datasets[0]['etudiants'], 'numero_inscription'),
            'The list follows the natural N° d\'inscription order'
        );
    }

    /**
     * The identifiers sheet carries every student's identifiant and mot de passe,
     * and the run text must stay XML-safe: the password generator's own symbol
     * set contains "&", which PhpWord writes raw unless escaping is enabled.
     */
    public function test_identifiants_lists_each_account_and_keeps_the_xml_well_formed(): void
    {
        $filiere = $this->makeFiliere('ACCOUNTS');
        $groupe = $this->makeGroup($filiere, 1, 'Groupe A', '2024/2025');

        $etudiant = $this->makeEtudiant($groupe, 'AMARA Samira', 'FA123456', '1 IP25', 'Ab1&R8x');
        $etudiant->user->withPlainPassword();

        $rows = [[
            'nom_prenom'         => $etudiant->nom_prenom,
            'numero_inscription' => $etudiant->numero_inscription,
            'identifiant'        => $etudiant->user->username,
            'mot_de_passe'       => $etudiant->user->password_plain,
        ]];

        $service = app(EtudiantDocxService::class);
        $path = $service->buildIdentifiants([
            'header' => ['filiere' => $filiere->nom, 'annee_scolaire' => $this->annee->label],
            'rows'   => $rows,
        ]);

        try {
            $document = $this->documentXml($path);

            $this->assertStringContainsString($etudiant->user->username, $document, 'The identifiant must be listed');
            $this->assertStringContainsString('Ab1&amp;R8x', $document, 'The password must be listed, ampersand escaped');
            $this->assertStringContainsString('AMARA Samira', $document);
            $this->assertStringContainsString('1 IP25', $document);
            $this->assertStringContainsString($filiere->nom, $document, 'The scope line names the filière');

            // Two identification-block rows + the column header + one student row.
            $this->assertSame(4, substr_count($document, '<w:tr>'));
        } finally {
            @unlink($path);
        }
    }

    public function test_identifiants_turns_missing_values_into_a_dash(): void
    {
        $service = app(EtudiantDocxService::class);
        $path = $service->buildIdentifiants([
            'header' => ['annee_scolaire' => $this->annee->label],
            'rows'   => [[
                'nom_prenom'         => 'SANS COMPTE',
                'numero_inscription' => '',
                'identifiant'        => null,
                'mot_de_passe'       => '',
            ]],
        ]);

        try {
            $document = $this->documentXml($path);
            $this->assertStringContainsString('SANS COMPTE', $document);
            $this->assertSame(3, substr_count($document, '>-</w:t>'), 'Every empty value prints as a dash');
        } finally {
            @unlink($path);
        }
    }

    public function test_both_docx_pages_are_a4_portrait_with_the_institute_header_and_footer(): void
    {
        $filiere = $this->makeFiliere('PAGE');
        $groupe = $this->makeGroup($filiere, 1, 'Groupe A', '2024/2025');
        $this->makeEtudiant($groupe, 'AMARA Samira', 'FA123456', '1 IP25');

        $datasets = $this->groupDatasets($filiere);

        $paths = [
            'liste'        => app(EtudiantDocxService::class)->buildListe($datasets),
            'identifiants' => app(EtudiantDocxService::class)->buildIdentifiants([
                'header' => ['filiere' => $filiere->nom],
                'rows'   => [[
                    'nom_prenom'         => 'AMARA Samira',
                    'numero_inscription' => '1 IP25',
                    'identifiant'        => 'amara.samira',
                    'mot_de_passe'       => 'Ab1&R8x',
                ]],
            ]),
        ];

        try {
            foreach ($paths as $kind => $path) {
                $document = $this->documentXml($path);

                // A4 in twips, portrait (no w:orient attribute).
                $this->assertStringContainsString('w:w="11906"', $document, "{$kind} must be an A4 portrait page");
                $this->assertStringContainsString('w:h="16838"', $document, "{$kind} must be an A4 portrait page");
                $this->assertStringNotContainsString('w:orient="landscape"', $document);

                $this->assertStringContainsString(
                    "Etablissement privé autorisé par L'État",
                    $this->partXml($path, 'word/header1.xml'),
                    "{$kind} must carry the authorization line"
                );
                $this->assertStringContainsString(
                    'www.institutifp.com',
                    $this->partXml($path, 'word/footer1.xml'),
                    "{$kind} must carry the institute footer"
                );
            }
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    public function test_download_names_report_the_applied_filters_only(): void
    {
        $service = app(EtudiantDocxService::class);

        $this->assertSame(
            'Liste stagiaire — Infirmier Polyvalent — 3ème année — 2025-2026.docx',
            $service->buildListeFilename([
                'filiere'        => 'Infirmier Polyvalent',
                'niveau'         => '3ème année',
                'annee_scolaire' => '2025/2026',
            ])
        );

        // No filière / niveau filter: only the school year is named, and the
        // school-year slash becomes a hyphen (Windows forbids it).
        $this->assertSame(
            'Liste stagiaire — 2025-2026.docx',
            $service->buildListeFilename(['annee_scolaire' => '2025/2026'])
        );

        $this->assertSame(
            'Identifiants et mots de passe — Infirmier Polyvalent — 2025-2026.docx',
            $service->buildIdentifiantsFilename([
                'filiere'        => 'Infirmier Polyvalent',
                'annee_scolaire' => '2025/2026',
            ])
        );
    }
}
