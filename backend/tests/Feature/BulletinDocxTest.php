<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\User;
use App\Services\BulletinDocxService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The bulletin is served as an editable .docx, one A4-portrait page per student,
 * styled after the reference document (identity block, units grid, bottom band
 * and signature line, institute header and footer). These tests pin that
 * contract: the download endpoints, the .docx validity, the reference layout
 * pieces, the title/conditional rows and the French number formatting.
 *
 * Cleanup deletes by primary key, hardened for the shared suite database like
 * the other docx tests.
 */
class BulletinDocxTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];
    private array $createdUnites = [];
    private ?AnneeAcademique $createdAnnee = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'bulletin.docx@test.local')->first()
            ?? User::create([
                'name'     => 'Bulletin docx admin',
                'email'    => 'bulletin.docx@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::where('is_current', false)->first()
            ?? ($this->createdAnnee = AnneeAcademique::create(['label' => 'BULLETIN TEST ' . Str::random(6), 'is_current' => true]));

        $this->assertNotNull($this->annee, 'An academic year is required to test bulletins');
    }

    protected function tearDown(): void
    {
        $etudiantIds = array_map(fn ($e) => $e->id, $this->createdEtudiants);
        $uniteIds = array_map(fn ($u) => $u->id, $this->createdUnites);

        if ($etudiantIds !== []) {
            Note::whereIn('etudiant_id', $etudiantIds)->delete();
        }
        if ($uniteIds !== []) {
            $sequenceIds = Sequence::whereIn('unite_id', $uniteIds)->pluck('id')->all();
            if ($sequenceIds !== []) {
                Controle::whereIn('sequence_id', $sequenceIds)->delete();
                Sequence::whereIn('id', $sequenceIds)->delete();
            }
        }

        if ($this->createdAnnee) {
            AnneeAcademique::where('id', $this->createdAnnee->id)->delete();
        }
        foreach ($this->createdUnites as $unite) {
            Unite::where('id', $unite->id)->delete();
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
     * One filière / niveau / groupe with a couple of units and $students
     * students. The programme is single-year, i.e. its own final year, so the
     * bulletin is a "fin de formation" one; a Soutenance unit is added when
     * $soutenance (which is what makes the NSTI row appear) and a Stage unit
     * when $stage.
     */
    private function makeHierarchy(string $suffix, int $students = 1, bool $soutenance = true, bool $stage = false): array
    {
        $filiere = Filiere::create([
            'nom'            => 'BUL ' . $suffix,
            'nom_ar'         => 'تمريض',
            'code'           => 'BL' . substr(md5($suffix . uniqid()), 0, 8),
            'section'        => 'Technicien Spécialisé',
            'type_formation' => 'Technicien',
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
            'nom'                 => 'SEC ' . $suffix,
            'promotion'           => '2024/2025',
        ]);
        $this->createdGroupes[] = $groupe;

        $etudiants = [];
        for ($i = 0; $i < max(1, $students); $i++) {
            $etudiants[] = $this->createdEtudiants[] = Etudiant::create([
                'user_id'             => $this->admin->id,
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'nom_prenom'          => 'BULLETIN Student ' . $suffix . ($i > 0 ? ' ' . $i : ''),
                'nom_ar'              => 'تلميذ',
                'cin'                 => 'BL' . substr(md5($suffix . $i . uniqid('', true)), 0, 10),
                'numero_inscription'  => 'INS' . substr(md5('b' . $suffix . $i . uniqid('', true)), 0, 14),
                'lieu_naissance'      => 'Oujda',
                'lieu_naissance_ar'   => 'وجدة',
                'nationalite'         => 'Marocaine',
                'nationalite_ar'      => 'مغربية',
                'status'              => 'active',
            ]);
        }

        $unites = [];
        foreach (['Soins de base', 'Hygiène hospitalière'] as $i => $nom) {
            $unite = Unite::create([
                'filiere_id'   => $filiere->id,
                'nom'          => $nom . ' ' . $suffix,
                'coefficient'  => $i + 2,
                'numero_annee' => $niveau->numero,
                'semestre'     => 1,
                'ordre'        => $i + 1,
                'is_active'    => true,
            ]);
            $this->createdUnites[] = $unite;
            $unites[] = $unite;
        }

        if ($soutenance) {
            $this->createdUnites[] = Unite::create([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Soutenance',
                'coefficient'  => 1,
                'numero_annee' => $niveau->numero,
                'semestre'     => 2,
                'ordre'        => 90,
                'is_active'    => true,
            ]);
        }

        if ($stage) {
            $this->createdUnites[] = Unite::create([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Stage',
                'coefficient'  => 4,
                'numero_annee' => $niveau->numero,
                'semestre'     => 2,
                'ordre'        => 91,
                'is_active'    => true,
            ]);
        }

        return compact('groupe', 'filiere', 'niveau', 'etudiants', 'unites');
    }

    /** Give the first student a CC mark of 15,5 through the ordinary grid. */
    private function gradeFirstStudent(array $h): void
    {
        $sequence = Sequence::create([
            'unite_id'         => $h['unites'][0]->id,
            'nom'              => 'S1 BULLETIN',
            'coefficient'      => 1,
            'nombre_controles' => 1,
        ]);
        $controle = Controle::create([
            'sequence_id' => $sequence->id,
            'numero'      => 1,
            'note_max'    => 20,
        ]);
        Note::create([
            'etudiant_id' => $h['etudiants'][0]->id,
            'controle_id' => $controle->id,
            'valeur'      => 15.5,
        ]);
    }

    public function test_bulletin_docx_endpoint_serves_the_docx_with_download_headers(): void
    {
        $h = $this->makeHierarchy('A');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/bulletin/docx?etudiant_id={$h['etudiants'][0]->id}&groupe_id={$h['groupe']->id}&annee_academique_id={$this->annee->id}");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );
        $disposition = urldecode((string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('BULLETIN', $disposition);
        $this->assertStringContainsString('BUL A', $disposition);
        $this->assertStringContainsString('.docx', $disposition);
    }

    public function test_all_bulletins_docx_endpoint_serves_one_page_per_student(): void
    {
        $h = $this->makeHierarchy('ALL', 4);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/bulletin/docx-all?groupe_id={$h['groupe']->id}&annee_academique_id={$this->annee->id}");

        $res->assertOk();
        $disposition = urldecode((string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('BULLETINS', $disposition);
        $this->assertStringContainsString('.docx', $disposition);

        // Each student gets its own portrait section, i.e. its own page.
        $phpWord = app(BulletinDocxService::class)->buildAllPhpWord(
            array_map(fn ($e) => ['nom_prenom' => $e->nom_prenom, 'numero_inscription' => $e->numero_inscription, 'unites' => []], $h['etudiants']),
            ['title' => 'Bulletin de fin de formation', 'filiere' => $h['filiere']->nom, 'section' => $h['filiere']->section, 'annee_scolaire' => '2025/2026', 'annee_formation' => '2024/2025']
        );
        $this->assertCount(4, $phpWord->getSections(), 'Every student must get its own section, i.e. its own page');
    }

    public function test_docx_page_is_a4_portrait(): void
    {
        $h = $this->makeHierarchy('PORT');
        $context = $this->contextFor($h);
        $bulletin = ['nom_prenom' => 'A', 'numero_inscription' => '1', 'unites' => []];

        $style = app(BulletinDocxService::class)->buildPhpWord($bulletin, $context)->getSections()[0]->getStyle();

        $this->assertSame('portrait', $style->getOrientation());
        $this->assertGreaterThan(
            $style->getPageSizeW(),
            $style->getPageSizeH(),
            'The A4 page must be portrait (height > width)'
        );
        $this->assertEqualsWithDelta(11907, (float) $style->getPageSizeW(), 5, 'A4 width in twips');
        $this->assertEqualsWithDelta(16840, (float) $style->getPageSizeH(), 5, 'A4 height in twips');
    }

    public function test_generated_docx_carries_the_reference_layout_parts(): void
    {
        $h = $this->makeHierarchy('B', 1, true, true);
        $this->gradeFirstStudent($h);

        $path = app(BulletinDocxService::class)->build(
            $this->bulletinFor($h),
            $this->contextFor($h)
        );

        try {
            $bytes = file_get_contents($path);
            $this->assertStringStartsWith('PK', $bytes, 'A .docx must be a valid (zip) package');
            $this->assertStringContainsString('word/media/', $bytes, 'The header must embed the institute logo');

            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $header = (string) $zip->getFromName('word/header1.xml');
            $footer = (string) $zip->getFromName('word/footer1.xml');
            $zip->close();

            // Title and identity block.
            foreach (['Bulletin de fin de formation', 'Nom et Prénom', 'N° inscription', 'Filière', 'Section', 'Année scolaire', 'Année de formation'] as $label) {
                $this->assertStringContainsString($label, $document, "The document must carry {$label}");
            }
            $this->assertStringContainsString($h['filiere']->nom, $document);
            $this->assertStringContainsString($h['filiere']->section, $document);

            // Units grid.
            foreach (['Unité', 'Coefficient', 'Notes CC', 'Note examen théorique', 'Note examen pratique'] as $label) {
                $this->assertStringContainsString($label, $document, "The units header must carry {$label}");
            }
            $this->assertStringContainsString('Soins de base B', $document);
            $this->assertStringContainsString('Stage', $document, 'The Stage unit is a year unit and shows in the grid');
            $this->assertStringContainsString('15,5', $document, 'Unit marks are trimmed French values');

            // Bottom band and signature.
            foreach (['Moyenne de note obtenue', 'Moyenne Générale /20', 'Décision du jury', 'Cachet et signature de la directrice'] as $label) {
                $this->assertStringContainsString($label, $document, "The band must carry {$label}");
            }
            $this->assertStringContainsString('Contrôle continu', $document);
            $this->assertStringContainsString('Examen théorique', $document);
            $this->assertStringContainsString('Examen pratique', $document);
            $this->assertStringContainsString('travaux individuels de fin de formation (20%)', $document);

            // Two bordered grids only: the units table and the band's two nested
            // grids. The identity block and the band container stay borderless;
            // a zero-size border would still print a hairline in Word.
            $this->assertSame(3, substr_count($document, '<w:tblBorders>'), 'Only the units grid and the band grids carry borders');
            $this->assertStringNotContainsString('w:sz="0"', $document);

            // Header and footer match the relevé wording.
            $this->assertStringContainsString("Etablissement privé autorisé par L'État", $header);
            $this->assertStringContainsString('www.institutifp.com', $footer);
            $this->assertStringContainsString('Adresse : Immeuble N° :25 Avenue Al Bakay Lhbil', $footer);
        } finally {
            @unlink($path);
        }
    }

    public function test_title_follows_the_final_year_of_the_programme(): void
    {
        $service = app(BulletinDocxService::class);

        $this->assertSame('Bulletin de fin de formation', $service->titleFor(3, 3));
        $this->assertSame('Bulletin de fin de formation', $service->titleFor(1, 1));
        $this->assertSame(
            'Bulletins de passage de la première année à la deuxième année',
            $service->titleFor(1, 3)
        );
        $this->assertSame(
            'Bulletins de passage de la deuxième année à la troisième année',
            $service->titleFor(2, 3)
        );
    }

    public function test_number_labels_match_the_reference(): void
    {
        $service = app(BulletinDocxService::class);

        // Weights: whole numbers for the official 30/20/30/20, French decimals
        // for the historical 36,67 / 26,66 / 36,67 split.
        $this->assertSame('30', $service->percentLabel(0.3));
        $this->assertSame('20', $service->percentLabel(0.2));
        $this->assertSame('36,67', $service->percentLabel(0.3667));
        $this->assertSame('26,66', $service->percentLabel(0.2666));

        // Unit cells trim trailing zeros; band cells keep two decimals.
        $this->assertSame('19', $service->cellValue(19.0));
        $this->assertSame('14,5', $service->cellValue(14.5));
        $this->assertSame('14,58', $service->cellValue(14.58));
        $this->assertSame('-', $service->cellValue(null));
        $this->assertSame('14,00', $service->numberLabel(14.0));
        $this->assertSame('16,12', $service->numberLabel(16.12));
        $this->assertSame('-', $service->numberLabel(null));
    }

    public function test_nsti_row_only_appears_when_the_programme_has_a_soutenance(): void
    {
        // A final year with a soutenance: the NSTI row is part of the band.
        $with = $this->makeHierarchy('SOUT1', 1, true);
        $path = app(BulletinDocxService::class)->build($this->bulletinFor($with), $this->contextFor($with));
        try {
            $this->assertStringContainsString(
                'travaux individuels de fin de formation',
                $this->documentXml($path),
                'A soutenance programme must show the NSTI row'
            );
        } finally {
            @unlink($path);
        }

        // A programme without a soutenance keeps the three components only.
        $without = $this->makeHierarchy('NOSOUT1', 1, false);
        $path = app(BulletinDocxService::class)->build($this->bulletinFor($without), $this->contextFor($without));
        try {
            $document = $this->documentXml($path);
            $this->assertStringNotContainsString('travaux individuels', $document);
            $this->assertStringContainsString('Contrôle continu (36,67%)', $document);
            $this->assertStringContainsString('Examen pratique (36,67%)', $document);
        } finally {
            @unlink($path);
        }
    }

    public function test_filenames_name_the_filiere_section_and_year(): void
    {
        $service = app(BulletinDocxService::class);
        $context = [
            'filiere'        => 'Infirmier Polyvalent',
            'section'        => 'Technicien Spécialisé',
            'annee_scolaire' => '2025/2026',
        ];

        $single = $service->buildFilename(['nom_prenom' => 'HAJI Nabil'], $context);
        $this->assertSame(
            'BULLETIN — Infirmier Polyvalent — Technicien Spécialisé — 2025-2026 — HAJI Nabil.docx',
            $single
        );

        $all = $service->buildAllFilename($context);
        $this->assertSame(
            'BULLETINS — Infirmier Polyvalent — Technicien Spécialisé — 2025-2026.docx',
            $all
        );
    }

    private function contextFor(array $h): array
    {
        return [
            'filiere'         => $h['filiere']->nom,
            'section'         => $h['filiere']->section,
            'annee_scolaire'  => '2025/2026',
            'annee_formation' => $h['groupe']->promotion,
            'title'           => 'Bulletin de fin de formation',
        ];
    }

    /** The bulletin payload the controller hands to the docx service. */
    private function bulletinFor(array $h): array
    {
        $bulletin = app(\App\Services\BulletinService::class)->calculateBulletin(
            $h['etudiants'][0]->id,
            $this->annee->id,
            null
        );

        return $bulletin + [
            'nom_prenom'         => $bulletin['student']->nom_prenom ?? '',
            'numero_inscription' => $bulletin['student']->numero_inscription ?? '',
        ];
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
}
