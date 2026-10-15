<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\StageNote;
use App\Models\Unite;
use App\Models\User;
use App\Services\RecapNoteDocxService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The relevé des notes is downloaded as an editable .docx jury sheet
 * styled after the reference recap document: A4 landscape, institute logo in
 * the page header, institute footer, jury / filière / grade / signature tables,
 * with the jury names left blank for handwriting. These tests pin that contract
 * (headers, .docx validity, and the reference layout pieces).
 *
 * Cleanup deletes by primary key, hardened for the shared suite database like
 * the other docx tests.
 */
class RecapNoteDocxTest extends TestCase
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

        $this->admin = User::where('email', 'recap.docx@test.local')->first()
            ?? User::create([
                'name'     => 'Recap docx admin',
                'email'    => 'recap.docx@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::where('is_current', false)->first()
            ?? ($this->createdAnnee = AnneeAcademique::create(['label' => 'RECAP TEST ' . Str::random(6), 'is_current' => true]));

        $this->assertNotNull($this->annee, 'An academic year is required to test recap docs');
    }

    protected function tearDown(): void
    {
        // Grades hang off the temporary students and units; remove them first so
        // the shared suite database never accumulates rows behind the FK deletes.
        $etudiantIds = array_map(fn ($e) => $e->id, $this->createdEtudiants);
        $uniteIds = array_map(fn ($u) => $u->id, $this->createdUnites);

        if ($etudiantIds !== []) {
            Note::whereIn('etudiant_id', $etudiantIds)->delete();
            StageNote::whereIn('etudiant_id', $etudiantIds)->delete();
            Examen::whereIn('etudiant_id', $etudiantIds)->delete();
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
     * One filière / niveau / groupe with two CC unites and one student, enough
     * for computeRecapData() to render a real grid. Pass a larger $students count
     * to reproduce a full class (the NSTI fit tests need one).
     *
     * The hierarchy is a one-year programme, i.e. its own final year, so it gets
     * a Soutenance unit by default ($soutenance) -- the reference recap is such a
     * programme. Pass false to model a year whose programme has no soutenance
     * (no NSTI). A Stage unit only exists when $stage is true, so the tests that
     * add their own Stage unit keep a single one.
     */
    private function makeHierarchy(string $suffix, int $students = 1, bool $soutenance = true, bool $stage = false): array
    {
        $filiere = Filiere::create([
            'nom'            => 'INF ' . $suffix,
            'nom_ar'         => 'تمريض',
            'code'           => 'RC' . substr(md5($suffix . uniqid()), 0, 8),
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

        $etudiant = null;
        for ($i = 0; $i < max(1, $students); $i++) {
            $etudiant = Etudiant::create([
                'user_id'             => $this->admin->id,
                'groupe_id'           => $groupe->id,
                'annee_academique_id' => $this->annee->id,
                'nom_prenom'          => 'RECAP Student ' . $suffix . ($i > 0 ? ' ' . $i : ''),
                'nom_ar'              => 'تلميذ',
                'cin'                 => 'RC' . substr(md5($suffix . $i . uniqid('', true)), 0, 10),
                'numero_inscription'  => 'INS' . substr(md5('r' . $suffix . $i . uniqid('', true)), 0, 14),
                'lieu_naissance'      => 'Oujda',
                'lieu_naissance_ar'   => 'وجدة',
                'nationalite'         => 'Marocaine',
                'nationalite_ar'      => 'مغربية',
                'status'              => 'active',
            ]);
            $this->createdEtudiants[] = $etudiant;
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

        // A Soutenance unit marks a final year that ends with a jury
        // presentation: it feeds the NSTI sheet and the synthesis's NSTI column.
        if ($soutenance) {
            $soutenanceUnite = Unite::create([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Soutenance',
                'coefficient'  => 1,
                'numero_annee' => $niveau->numero,
                'semestre'     => 2,
                'ordre'        => 90,
                'is_active'    => true,
            ]);
            $this->createdUnites[] = $soutenanceUnite;
        }

        // The Stage spans the whole year and is excluded from the per-semestre
        // tables, so it only shows up in the year tables.
        if ($stage) {
            $stageUnite = Unite::create([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Stage',
                'coefficient'  => 4,
                'numero_annee' => $niveau->numero,
                'semestre'     => 2,
                'ordre'        => 91,
                'is_active'    => true,
            ]);
            $this->createdUnites[] = $stageUnite;
        }

        return compact('groupe', 'filiere', 'niveau', 'etudiant', 'unites');
    }

    private function recapData(int $groupeId, string $type): array
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/recap-notes?groupe_id={$groupeId}&type={$type}");
        $res->assertOk();

        return $res->json('data');
    }

    public function test_recap_docx_endpoint_serves_the_docx_with_download_headers(): void
    {
        $h = $this->makeHierarchy('A');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/recap-notes/docx?groupe_id={$h['groupe']->id}&type=mpcc1");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );
        $this->assertStringContainsString('MPCC1', $res->headers->get('content-disposition'));
        $this->assertStringContainsString('.docx', $res->headers->get('content-disposition'));
    }

    public function test_all_tables_docx_endpoint_serves_every_table_in_one_file(): void
    {
        $h = $this->makeHierarchy('ALL');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/recap-notes/docx-all?groupe_id={$h['groupe']->id}");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );

        $disposition = urldecode((string) $res->headers->get('content-disposition'));
        $this->assertStringContainsString('Tous les tableaux', $disposition);
        $this->assertStringContainsString('.docx', $disposition);
    }

    public function test_build_all_renders_one_page_per_table_with_its_own_average_header(): void
    {
        // A final-year programme with a soutenance and a Stage unit: every one of
        // the twelve tables exists, so the combined file really is twelve pages.
        $h = $this->makeHierarchy('ALL2', 1, true, true);
        $service = app(RecapNoteDocxService::class);

        $datasets = [];
        foreach (RecapNoteDocxService::TABLE_TYPES as $type) {
            $data = $this->recapData($h['groupe']->id, $type);
            $data['table_type'] = $type;
            $datasets[] = $data;
        }

        $phpWord = $service->buildAllPhpWord($datasets);
        $this->assertCount(
            count(RecapNoteDocxService::TABLE_TYPES),
            $phpWord->getSections(),
            'Each table must get its own landscape section, i.e. its own page'
        );

        $path = $service->buildAll($datasets);
        try {
            $this->assertStringStartsWith('PK', (string) file_get_contents($path), 'The combined file must be a valid (zip) package');

            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            // Every table's average-column header really made it into the
            // document. MPCC* and NSTI* also name synthesis columns, so they
            // appear twice; the Stage sheet has no average column at all.
            foreach ([
                'MPCC1*' => 1,
                'MPCC2*' => 1,
                'MPCC*' => 2,
                'MPEFCFT1*' => 1,
                'MPEFCFT2*' => 1,
                'MPET*' => 1,
                'MPEFCFP1*' => 1,
                'MPEFCFP2*' => 1,
                'MPEP*' => 1,
                'NSTI*' => 2,
                'NF*****' => 1,
            ] as $label => $count) {
                $this->assertSame($count, substr_count($document, $label), "The {$label} header must appear {$count} time(s)");
            }
            $this->assertSame(
                count(RecapNoteDocxService::TABLE_TYPES),
                substr_count($document, '<w:sectPr'),
                'There must be one section (page) per table'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_all_tables_filename_names_the_filiere_and_says_all_tables(): void
    {
        $h = $this->makeHierarchy('ALLN');
        $data = $this->recapData($h['groupe']->id, 'mpcc1');
        $data['table_type'] = 'mpcc1';

        $name = app(RecapNoteDocxService::class)->buildAllFilename($data);

        $this->assertStringContainsString('RELEVÉ DES NOTES', $name);
        $this->assertStringContainsString('INF ALLN', $name);
        $this->assertStringContainsString('Tous les tableaux', $name);
        $this->assertStringEndsWith('.docx', $name);
    }

    public function test_generated_docx_carries_the_reference_layout_parts(): void
    {
        $h = $this->makeHierarchy('B');
        $data = $this->recapData($h['groupe']->id, 'mpcc1');
        $data['table_type'] = 'mpcc1';

        $path = app(RecapNoteDocxService::class)->build($data);

        try {
            $bytes = file_get_contents($path);
            $this->assertStringStartsWith('PK', $bytes, 'A .docx must be a valid (zip) package');
            $this->assertStringContainsString('word/document.xml', $bytes);
            $this->assertStringContainsString('word/media/', $bytes, 'The header must embed the institute logo');

            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $header = (string) $zip->getFromName('word/header1.xml');
            $footer = (string) $zip->getFromName('word/footer1.xml');
            $zip->close();

            $this->assertStringContainsString(
                'Membre de jury présent',
                $document,
                'The jury banner must match the reference wording'
            );
            $this->assertStringContainsString(
                'Tableau récapitulatif des résultats des contrôles continus (BLOC1)',
                $document,
                'The grade title must match the reference wording'
            );
            $this->assertStringContainsString('MPCC1', $document, 'The average column header is MPCC*');
            $this->assertStringContainsString('vMerge', $document, 'Name/MPCC/Observation must be vertically merged');
            $this->assertStringContainsString('gridSpan', $document, 'The title must span every subject column');
            $this->assertStringContainsString('F2F2F2', $document, 'Header/class-average cells are shaded');
            $this->assertStringContainsString('landscape', $document, 'The page must be A4 landscape');
            $this->assertStringContainsString(
                'www.institutifp.com',
                $footer,
                'The footer must carry the institute website'
            );
            $this->assertStringContainsString(
                "Etablissement privé autorisé par L'État",
                $header,
                'The header must carry the authorization line'
            );

            // Jury names stay blank for handwriting.
            $this->assertStringNotContainsString('Président de jury : Dr', $document);
        } finally {
            @unlink($path);
        }
    }

    public function test_title_and_filename_name_the_group_filiere_and_table(): void
    {
        $h = $this->makeHierarchy('TITLE');
        $data = $this->recapData($h['groupe']->id, 'mpcc2');
        $data['table_type'] = 'mpcc2';

        $service = app(RecapNoteDocxService::class);
        $title = $service->pageTitleFor('mpcc2', $data['header']);

        $this->assertStringContainsString('RELEVÉ DES NOTES', $title, 'The title opens with the doc type');
        $this->assertStringContainsString('INF ' . 'TITLE', $title, 'The title names the filière');
        $this->assertStringContainsString('SEC ' . 'TITLE', $title, 'The title names the groupe');
        $this->assertStringContainsString('MPCC2', $title, 'The title names the table');

        // The page no longer prints the title; it lives on in the file name so
        // the downloaded sheet is instantly recognisable.
        $filename = $service->buildFilename($data);
        $this->assertSame(str_replace('/', '-', $title) . '.docx', $filename);
        $this->assertStringEndsWith('.docx', $filename);
        $this->assertStringContainsString('RELEVÉ DES NOTES', $filename);
        $this->assertStringContainsString('MPCC2', $filename);

        // Windows forbids a slash, so the school year separator becomes a hyphen
        // while accents stay as typed.
        $accented = $service->buildFilename([
            'table_type' => 'mpcc1',
            'header'     => [
                'filiere'        => 'Infirmier Polyvalent',
                'section'        => 'Technicien Spécialisé',
                'annee_scolaire' => '2025/2026',
            ],
        ]);
        $this->assertSame(
            'RELEVÉ DES NOTES — Infirmier Polyvalent — Technicien Spécialisé — 2025-2026 — MPCC1.docx',
            $accented
        );

        $path = $service->build($data);
        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();
            $this->assertStringNotContainsString(
                'RELEVÉ DES NOTES — INF TITLE — SEC TITLE',
                $document,
                'The page must not carry the naming title (filename only)'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_docx_page_is_true_a4_landscape(): void
    {
        $h = $this->makeHierarchy('LAND');
        $data = $this->recapData($h['groupe']->id, 'mpcc1');
        $data['table_type'] = 'mpcc1';

        $phpWord = app(RecapNoteDocxService::class)->buildPhpWord($data);
        $style = $phpWord->getSections()[0]->getStyle();

        $this->assertSame('landscape', $style->getOrientation());
        $this->assertGreaterThan(
            $style->getPageSizeH(),
            $style->getPageSizeW(),
            'The A4 page must be landscape (width > height)'
        );
        $this->assertEquals(16838, (int) round($style->getPageSizeW()));
        $this->assertEquals(11906, (int) round($style->getPageSizeH()));
    }

    public function test_exam_units_trim_decimals_while_cc_keep_two(): void
    {
        $service = app(RecapNoteDocxService::class);

        $this->assertSame('16', $service->formatValue(16.0, true));
        $this->assertSame('19,5', $service->formatValue(19.5, true));
        $this->assertSame('19,00', $service->formatValue(19.0, false));
        $this->assertSame('', $service->formatValue(null, false));
    }

    public function test_titles_and_average_labels_match_the_reference(): void
    {
        $service = app(RecapNoteDocxService::class);

        $this->assertSame(
            'Tableau récapitulatif du résultat des examens Théorique (BLOC1)',
            $service->tableTitleFor('mpefcf1')
        );
        $this->assertSame('MPCC1*', $service->avgLabelFor('mpcc1'));
        $this->assertSame('MPEFCFT2*', $service->avgLabelFor('mpefcf2'));
        $this->assertSame('MPET*', $service->avgLabelFor('mpefcf_global'));
        $this->assertSame('MPEP*', $service->avgLabelFor('mpefcfp_global'));

        // A synthesis coefficient is a whole number when the weight is exact
        // (30/20/30/20) and a two-decimal French value when it is not.
        $this->assertSame('30', $service->coefficientLabel(30));
        $this->assertSame('20', $service->coefficientLabel(20.0));
        $this->assertSame('36,67', $service->coefficientLabel(36.67));
        $this->assertSame('26,66', $service->coefficientLabel(26.66));
    }

    /**
     * The Stage spans the whole year, so it is not a Bloc 1 / Bloc 2 unit: it
     * must stay out of every per-semestre table as well as the théorique global,
     * and only show up in the full-year MPCC and the full-year pratique exams.
     */
    public function test_stage_unit_is_a_year_unit_kept_out_of_the_per_semestre_tables(): void
    {
        $h = $this->makeHierarchy('STAGE');

        $unite2 = Unite::create([
            'filiere_id'   => $h['filiere']->id,
            'nom'          => 'Bloc 2 unit ' . 'STAGE',
            'coefficient'  => 2,
            'numero_annee' => $h['niveau']->numero,
            'semestre'     => 2,
            'ordre'        => 10,
            'is_active'    => true,
        ]);
        $this->createdUnites[] = $unite2;

        // A real Stage unit, stored on semestre 2 like the official curriculum
        // does, but which must never be counted as a Bloc 1 / Bloc 2 unit.
        $stage = Unite::create([
            'filiere_id'   => $h['filiere']->id,
            'nom'          => 'Stage',
            'coefficient'  => 4,
            'numero_annee' => $h['niveau']->numero,
            'semestre'     => 2,
            'ordre'        => 11,
            'is_active'    => true,
        ]);
        $this->createdUnites[] = $stage;

        $subjectsOf = fn (string $type): array => array_map(
            fn (array $subject) => $subject['nom'],
            $this->recapData($h['groupe']->id, $type)['subjects']
        );

        // Nowhere in the per-semestre Bloc 1 / Bloc 2 tables (CC or exams)...
        foreach (['mpcc1', 'mpcc2', 'mpefcf1', 'mpefcf2', 'mpefcfp1', 'mpefcfp2'] as $type) {
            $this->assertNotContains('Stage', $subjectsOf($type), "The Stage must not appear in {$type}");
        }

        // ... and not in the théorique global either.
        $this->assertNotContains('Stage', $subjectsOf('mpefcf_global'));

        // It only lives in the full-year MPCC and the full-year pratique exams.
        $this->assertContains('Stage', $subjectsOf('mpcc_global'));
        $this->assertContains('Stage', $subjectsOf('mpefcfp_global'));

        // Ordinary S2 units still render in the Bloc 2 table.
        $this->assertContains($unite2->nom, $subjectsOf('mpcc2'));
    }

    /**
     * The NSTI sheet is the jury's presentation grid ("Critères arrêtés par le
     * jury d'examens"): fixed criteria with their coefficients and no marks yet.
     */
    public function test_nsti_sheet_lists_the_jury_criteria_and_stays_empty(): void
    {
        $h = $this->makeHierarchy('NSTI');

        $data = $this->recapData($h['groupe']->id, 'nsti');

        $this->assertCount(10, $data['subjects'], 'The NSTI sheet lists the ten jury criteria');
        $this->assertSame('Intérêt du sujet traité', $data['subjects'][0]['nom']);
        $this->assertSame(3, $data['subjects'][0]['coefficient']);
        $this->assertSame(2, $data['subjects'][1]['coefficient']);
        $this->assertSame("Maitrise de soi durant l'exposé", $data['subjects'][9]['nom']);
        $this->assertSame(1, $data['subjects'][9]['coefficient']);

        // Empty sheet: the students are listed but carry no note and no average.
        $this->assertNotEmpty($data['students']);
        $this->assertNull($data['students'][0]['average']);
        foreach ($data['students'][0]['notes'] as $note) {
            $this->assertNull($note);
        }

        $service = app(RecapNoteDocxService::class);
        $this->assertSame("Critères arrêtés par le jury d'examens", $service->tableTitleFor('nsti'));
        $this->assertSame('NSTI*', $service->avgLabelFor('nsti'));
        $this->assertSame('NSTI', $service->tableLabelFor('nsti'));
        $this->assertSame(
            "Proposition/ commentaire de jury d'examen",
            $service->obsLabelFor('nsti')
        );
    }

    /**
     * The NSTI header row carries the ten long jury criteria, so it wraps to
     * several lines and used to push sizeToFit() into shrinking every font on the
     * sheet (the criterion names fell to ~4.5pt). Its tighter data-row pitch must
     * keep the whole sheet at the same font scale as the other 10-column table.
     */
    public function test_nsti_sheet_keeps_the_same_font_scale_as_the_other_tables(): void
    {
        $h = $this->makeHierarchy('NSTIFIT', 30);

        $service = app(RecapNoteDocxService::class);
        $fit = new \ReflectionMethod($service, 'sizeToFit');

        $unit = $this->recapData($h['groupe']->id, 'mpcc_global');
        $unit['table_type'] = 'mpcc_global';

        $nsti = $this->recapData($h['groupe']->id, 'nsti');
        $nsti['table_type'] = 'nsti';

        $unitLayout = $fit->invoke($service, $unit);
        $nstiLayout = $fit->invoke($service, $nsti);

        $this->assertSame(
            $unitLayout['scale'],
            $nstiLayout['scale'],
            'The NSTI sheet must not shrink its fonts below the other tables'
        );
        $this->assertLessThan(
            $unitLayout['student_h'],
            $nstiLayout['student_h'],
            'The NSTI sheet runs its data rows at the tighter reference pitch'
        );
    }

    /**
     * The Stage sheet is the jury's stage grid: one Évaluation column and one
     * Examen pratique column, both coefficient 4, and no average column — exactly
     * the reference stage page.
     */
    public function test_stage_sheet_lists_the_two_stage_marks_and_no_average_column(): void
    {
        $h = $this->makeHierarchy('STG2');

        $data = $this->recapData($h['groupe']->id, 'stage');

        $this->assertSame(
            ["Note d'évaluation", "Note d'examen pratique"],
            array_map(fn ($s) => $s['nom'], $data['subjects'])
        );
        $this->assertSame([4, 4], array_map(fn ($s) => $s['coefficient'], $data['subjects']));

        // No average column and no class-average row, like the reference.
        $this->assertNull($data['class_average']);
        foreach ($data['students'] as $student) {
            $this->assertArrayHasKey('stage_eval', $student['notes']);
            $this->assertArrayHasKey('stage_exam', $student['notes']);
        }

        $service = app(RecapNoteDocxService::class);
        $this->assertSame('', $service->avgLabelFor('stage'));
        $this->assertSame('STAGE', $service->tableLabelFor('stage'));
        $this->assertSame(
            'Tableau récapitulatif des résultats de stage (durant tout le cursus de formation)',
            $service->tableTitleFor('stage')
        );
        $this->assertSame(
            "Proposition/ commentaire de jury d'examen",
            $service->obsLabelFor('stage')
        );

        $data['table_type'] = 'stage';
        $path = $service->build($data);
        try {
            $document = $this->documentXml($path);
            $this->assertStringContainsString("Note d'évaluation", $document);
            $this->assertStringContainsString("Note d'examen pratique", $document);
            $this->assertStringContainsString('durant tout le cursus de formation', $document);
            $this->assertStringNotContainsString('Moyenne de la classe', $document);
            $this->assertStringNotContainsString('Observation des formateurs', $document);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The synthesis sheet is the fin-cursus final mark: the four global averages
     * weighted 30 / 20 / 30 / 20, with no title row and no class-average row.
     */
    public function test_synthesis_sheet_weights_the_four_global_marks_into_the_final_nf(): void
    {
        $h = $this->makeHierarchy('SYNTH');
        $etudiant = $h['etudiant'];

        // One CC mark (feeds MPCC) and one theoretical + one practical exam
        // (feed MPEFCFT / MPEFCFP); the NSTI column stays blank.
        $sequence = Sequence::create([
            'unite_id'         => $h['unites'][0]->id,
            'nom'              => 'S1 SYNTH',
            'coefficient'      => 1,
            'nombre_controles' => 1,
        ]);
        $controle = Controle::create([
            'sequence_id' => $sequence->id,
            'numero'      => 1,
            'note_max'    => 20,
        ]);
        Note::create([
            'etudiant_id' => $etudiant->id,
            'controle_id' => $controle->id,
            'valeur'      => 12,
        ]);
        foreach (['theorique' => 16, 'pratique' => 18] as $type => $valeur) {
            Examen::create([
                'etudiant_id'         => $etudiant->id,
                'unite_id'            => $h['unites'][0]->id,
                'bloc'                => 1,
                'type'                => $type,
                'semestre'            => 1,
                'annee_academique_id' => $this->annee->id,
                'valeur'              => $valeur,
            ]);
        }

        $data = $this->recapData($h['groupe']->id, 'synthese');

        $this->assertSame(
            ['MPCC*', 'MPEFCFT**', 'MPEFCFP***', 'NSTI****'],
            array_map(fn ($s) => $s['nom'], $data['subjects'])
        );
        $this->assertSame([30, 20, 30, 20], array_map(fn ($s) => $s['coefficient'], $data['subjects']));
        $this->assertNull($data['class_average'], 'The synthesis sheet has no class-average row');

        $student = collect($data['students'])->firstWhere('id', $etudiant->id);
        $this->assertNotNull($student);

        // Each column is the student's average on the matching global sheet.
        $this->assertEquals(12.0, $student['notes']['mpcc']);
        $this->assertEquals(16.0, $student['notes']['mpefcf']);
        $this->assertEquals(18.0, $student['notes']['mpefcfp']);
        $this->assertNull($student['notes']['nsti']);

        // NF = the 30/20/30/20 mean of the marks that exist.
        $this->assertEquals(
            round((30 * 12 + 20 * 16 + 30 * 18) / (30 + 20 + 30), 2),
            $student['average']
        );

        $service = app(RecapNoteDocxService::class);
        $this->assertSame('NF*****', $service->avgLabelFor('synthese'));
        $this->assertSame('SYNTHÈSE', $service->tableLabelFor('synthese'));
        $this->assertSame('', $service->tableTitleFor('synthese'));

        $data['table_type'] = 'synthese';
        $path = $service->build($data);
        try {
            $document = $this->documentXml($path);
            foreach (['MPCC*', 'MPEFCFT**', 'MPEFCFP***', 'NSTI****', 'NF*****'] as $label) {
                $this->assertStringContainsString($label, $document, "The synthesis sheet must carry {$label}");
            }
            $this->assertStringNotContainsString('Moyenne de la classe', $document);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A programme whose year has no soutenance has no NSTI at all: the synthesis
     * drops the NSTI column and NF falls back to the historical
     * 36,67 / 26,66 / 36,67 split, exactly like the bulletin's NF.
     */
    public function test_synthesis_without_soutenance_drops_nsti_and_uses_the_historical_weights(): void
    {
        $h = $this->makeHierarchy('NOSOUT', 1, false);

        $data = $this->recapData($h['groupe']->id, 'synthese');

        $this->assertSame(
            ['MPCC*', 'MPEFCFT**', 'MPEFCFP***'],
            array_map(fn ($s) => $s['nom'], $data['subjects']),
            'The synthesis must not carry an NSTI column without a soutenance'
        );
        $this->assertEquals(
            [36.67, 26.66, 36.67],
            array_map(fn ($s) => $s['coefficient'], $data['subjects'])
        );

        $data['table_type'] = 'synthese';
        $path = app(RecapNoteDocxService::class)->build($data);
        try {
            $document = $this->documentXml($path);
            $this->assertStringNotContainsString('NSTI', $document);
            $this->assertStringContainsString('36,67', $document, 'The coefficient row shows the historical weights');
            $this->assertStringContainsString('NF*****', $document);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The "Tableau" picker reads this list, so it must never offer NSTI to a
     * filière/year without a soutenance, nor Stage to one without a Stage unit.
     */
    public function test_available_tables_drop_nsti_and_stage_when_the_filiere_has_neither(): void
    {
        $h = $this->makeHierarchy('AVAIL0', 1, false, false);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/recap-notes/table-types?groupe_id={$h['groupe']->id}");

        $res->assertOk();
        $available = $res->json('data');

        $this->assertSame(
            array_values(array_diff(RecapNoteDocxService::TABLE_TYPES, ['nsti', 'stage'])),
            $available
        );
        $this->assertNotContains('nsti', $available);
        $this->assertNotContains('stage', $available);

        // The single-table read exposes the same list to the page.
        $notes = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/recap-notes?groupe_id={$h['groupe']->id}&type=mpcc1");
        $notes->assertOk();
        $this->assertSame($available, $notes->json('data.available_tables'));
    }

    public function test_available_tables_include_nsti_and_stage_when_the_filiere_has_both(): void
    {
        $h = $this->makeHierarchy('AVAIL1', 1, true, true);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/recap-notes/table-types?groupe_id={$h['groupe']->id}");

        $res->assertOk();
        $this->assertSame(RecapNoteDocxService::TABLE_TYPES, $res->json('data'));
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