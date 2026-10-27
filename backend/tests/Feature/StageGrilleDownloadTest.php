<?php

namespace Tests\Feature;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;
use Tests\TestCase;

/**
 * The Stage page's "Télécharger la grille" button pulls a Word sheet through
 * these endpoints: one student or a whole group, blank (identity only) or filled
 * with the saved marks. The sheet is the institute's own file, filled in, so the
 * stage place comes from the calendar rather than from the dialog.
 *
 * The tests reuse the fixture students, so nothing is written to the database.
 */
class StageGrilleDownloadTest extends TestCase
{
    private Etudiant $etudiant;
    private User $admin;
    private int $groupeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->etudiant = Etudiant::whereNotNull('groupe_id')
            ->whereHas('groupe.niveau.filiere')
            ->first();
        $this->assertNotNull($this->etudiant, 'Fixtures need one student with a groupe/niveau/filiere.');

        $this->groupeId = (int) Etudiant::whereNotNull('groupe_id')
            ->whereHas('groupe.niveau.filiere')
            ->selectRaw('groupe_id, COUNT(*) as total')
            ->groupBy('groupe_id')
            ->orderByDesc('total')
            ->value('groupe_id');
        $this->assertGreaterThan(1, Etudiant::where('groupe_id', $this->groupeId)->count());

        $this->admin = User::where('email', 'stage.grille@test.local')->first()
            ?? User::create([
                'name'     => 'Stage grille admin',
                'email'    => 'stage.grille@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
    }

    /** The sheet's main part, uncompressed, so its text can be inspected. */
    private function documentXml(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            Settings::setZipClass(Settings::PCLZIP);
        }

        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    public function test_the_evaluation_sheet_is_built_as_a_docx(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildEvaluation(
            ['nom_prenom' => 'Test Étudiant', 'filiere' => 'Infirmier Polyvalent (2ème année)'],
            [
                'duree'    => '4 semaines',
                'lieu'     => 'Edderrak',
                'service'  => 'Médecine',
                'pratique' => 'Pose de perfusion',
                'data'     => null,
            ]
        );

        $this->assertFileExists($path);
        $this->assertSame('PK', substr((string) file_get_contents($path), 0, 2));

        $xml = $this->documentXml($path);
        $this->assertStringContainsString('Edderrak', $xml);
        $this->assertStringContainsString('4 semaines', $xml);
        $this->assertStringContainsString('du .../.../.... au .../.../....', $xml);
        $this->assertStringContainsString('Pose de perfusion', $xml);
        $this->assertStringContainsString('Médecine', $xml);
        $this->assertStringNotContainsString('${', $xml, 'Every placeholder must be filled.');

        @unlink($path);
    }

    public function test_the_evaluation_sheet_closes_with_the_service_and_the_practice(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildEvaluation(
            ['nom_prenom' => 'Test Étudiant'],
            [
                'lieu'    => 'Edderrak',
                'service' => 'Médecine',
                // No practice typed in the dialog: the saved sheet provides it.
                'data'    => [
                    'comportement'      => [],
                    'aptitudes'         => [],
                    'pratique_executee' => 'Pose de perfusion',
                ],
            ]
        );

        $xml = $this->documentXml($path);

        $this->assertStringContainsString('Pratique exécutée', $xml);
        $this->assertStringContainsString('Pose de perfusion', $xml);
        $this->assertStringContainsString('Service', $xml);
        $this->assertStringContainsString('Médecine', $xml);
        $this->assertStringNotContainsString('${', $xml, 'Every placeholder must be filled.');

        @unlink($path);
    }

    public function test_a_long_duration_is_shrunk_to_fit_its_cell(): void
    {
        $duree = 'Stage de 4 semaines du lundi 01/10/2025 au vendredi 31/10/2025 inclus';

        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildEvaluation(
            ['nom_prenom' => 'Test Étudiant'],
            [
                'duree'   => $duree,
                'lieu'    => 'Edderrak',
                'service' => 'Médecine',
                'data'    => null,
            ]
        );

        $xml = $this->documentXml($path);
        $this->assertStringContainsString(htmlspecialchars($duree, ENT_QUOTES | ENT_XML1, 'UTF-8'), $xml);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // The run that carries the duration: its size must have been reduced
        // from the template's 10pt (20 half-points) to fit the cell.
        $size = null;
        foreach ($xp->query('//w:t') as $t) {
            if (!str_contains($t->textContent, '01/10/2025')) {
                continue;
            }

            $run = $xp->query('ancestor::w:r', $t)->item(0);
            $sz = $run ? $xp->query('./w:rPr/w:sz', $run)->item(0) : null;
            if ($sz) {
                $size = (int) $sz->getAttribute('w:val');
                break;
            }
        }

        $this->assertNotNull($size, 'The duration must be printed.');
        $this->assertLessThan(20, $size, 'A long duration must be shrunk below the label size.');
        $this->assertGreaterThanOrEqual(12, $size, 'The value must not go below the 6pt floor.');

        @unlink($path);
    }

    public function test_the_exam_sheet_is_built_with_filled_marks(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildExamen(
            ['nom_prenom' => 'Test Étudiant'],
            [
                'lieu'    => 'Moulouya',
                'service' => 'Médecine',
                'data'    => [
                    'pratique_executee' => 'Pose de perfusion',
                    'lignes' => array_fill(0, 7, ['note' => 1, 'observation' => 'Très bien']),
                ],
            ]
        );

        $this->assertFileExists($path);
        $this->assertSame('PK', substr((string) file_get_contents($path), 0, 2));

        $xml = $this->documentXml($path);
        // the place and the service sit on the same line, kept apart by a gap
        $this->assertStringContainsString('Moulouya', $xml);
        $this->assertStringContainsString('Médecine', $xml);
        $this->assertMatchesRegularExpression('/Moulouya\s{2,}Médecine/u', $xml);
        $this->assertStringContainsString('Pose de perfusion', $xml);
        $this->assertStringContainsString('Très bien', $xml);
        $this->assertStringNotContainsString('${', $xml, 'Every placeholder must be filled.');

        @unlink($path);
    }

    public function test_the_blank_exam_sheet_keeps_its_dotted_guides(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildExamen(
            ['nom_prenom' => 'Test Étudiant', 'numero_inscription' => '76 IP25'],
            ['lieu' => 'Edderrak', 'service' => 'Médecine', 'data' => null]
        );

        $xml = $this->documentXml($path);

        $this->assertStringContainsString('76 IP25', $xml);
        $this->assertStringContainsString('…', $xml, 'The form keeps its dotted lines.');
        $this->assertStringNotContainsString('${', $xml, 'Every placeholder must be filled.');

        // The guides are one line each. A single run of dots longer than the
        // cell cannot wrap, and stretches the whole table over several pages.
        $this->assertSame(
            0,
            preg_match('/…{60,}/u', $xml),
            'A dotted guide must stay on its own line.'
        );

        @unlink($path);
    }

    /** The rows of the exam sheet's barème table, one array of cell texts per row. */
    private function examRows(string $path): array
    {
        $dom = new \DOMDocument();
        $dom->loadXML($this->documentXml($path));

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xp->query('//w:tbl') as $tbl) {
            $text = '';
            foreach ($xp->query('.//w:t', $tbl) as $t) {
                $text .= $t->textContent;
            }

            if (!str_contains($text, 'Élément à évaluer')) {
                continue;
            }

            $rows = [];
            foreach ($xp->query('./w:tr', $tbl) as $tr) {
                $cells = [];
                foreach ($xp->query('./w:tc', $tr) as $tc) {
                    $cell = '';
                    foreach ($xp->query('.//w:t', $tc) as $t) {
                        $cell .= $t->textContent;
                    }
                    $cells[] = trim($cell);
                }
                $rows[] = $cells;
            }

            return $rows;
        }

        return [];
    }

    public function test_the_exam_sheet_prints_the_saved_notes_in_the_note_column(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildExamen(
            ['nom_prenom' => 'Test Étudiant'],
            [
                'lieu'    => 'Moulouya',
                'service' => 'Chirurgie',
                // The exact shape the Stage page saves: one grid per service.
                'data'    => [
                    'Chirurgie' => [
                        'lieu'              => null,
                        'lignes'            => [
                            ['note' => '0.6', 'observation' => 'Bien'],
                            ['note' => '0.6', 'observation' => 'Moyen'],
                            ['note' => '0.4', 'observation' => 'Bien'],
                            ['note' => '1',   'observation' => 'Très bien'],
                            ['note' => '0.2', 'observation' => 'Moyen'],
                            ['note' => '0.4', 'observation' => 'Moyen'],
                            ['note' => '0.4', 'observation' => 'Insuffisant'],
                        ],
                        'pratique_executee' => 'aaa',
                    ],
                ],
            ]
        );

        $rows = $this->examRows($path);

        $this->assertCount(9, $rows);
        $this->assertSame('0.6', $rows[1][2]);
        $this->assertSame('0.6', $rows[2][2]);
        $this->assertSame('0.4', $rows[3][2]);
        $this->assertSame('1', $rows[4][2]);
        $this->assertSame('0.2', $rows[5][2]);
        $this->assertSame('0.4', $rows[6][2]);
        $this->assertSame('0.4', $rows[7][2]);
        $this->assertSame('3.6', $rows[8][2], 'The total column must sum the marks.');
        $this->assertSame('Bien', $rows[1][3]);

        @unlink($path);
    }

    public function test_the_blank_exam_sheet_keeps_the_note_column_empty(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildExamen(
            ['nom_prenom' => 'Test Étudiant'],
            ['lieu' => 'Edderrak', 'service' => 'Médecine', 'data' => null]
        );

        $rows = $this->examRows($path);

        $this->assertCount(9, $rows);
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $row) {
            $this->assertSame('', $rows[$row][2], "Row {$row} of a blank sheet must be left for handwriting.");
        }

        @unlink($path);
    }

    public function test_the_evaluation_sheet_reads_the_saved_service_grid(): void
    {
        $service = app(\App\Services\StageGrilleDocxService::class);
        $path = $service->buildEvaluation(
            ['nom_prenom' => 'Test Étudiant'],
            [
                'lieu'    => 'Edderrak',
                'service' => 'Médecine',
                'data'    => [
                    'Médecine' => [
                        'comportement'      => [4, 3, 2, 1, 0],
                        'aptitudes'         => [4, 4, 3, 2, 1, 0, 1, 2, 3, 4],
                        'pratique_executee' => 'Pose de perfusion',
                    ],
                ],
            ]
        );

        $xml = $this->documentXml($path);

        $this->assertStringContainsString('Pose de perfusion', $xml);
        $this->assertStringContainsString('Médecine', $xml);
        $this->assertStringNotContainsString('${', $xml, 'Every placeholder must be filled.');

        @unlink($path);
    }

    public function test_a_single_student_sheet_can_be_downloaded(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->get(
            '/api/admin/stage/grille/download?type=grille_evaluation&etudiant_id='
            . $this->etudiant->id . '&mode=blank&duree=4%20semaines'
        );

        $res->assertOk();
        $res->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );
        $this->assertStringContainsString('.docx', (string) $res->headers->get('content-disposition'));
    }

    public function test_a_whole_group_is_downloaded_as_a_zip_of_word_files(): void
    {
        // The biggest group the stage calendar covers, so the archive has several
        // students to hold.
        $etudiants = Etudiant::with('groupe')
            ->whereHas('groupe.niveau.filiere')
            ->where('groupe_id', $this->groupeId)
            ->orderBy('nom_prenom')
            ->get();

        $res = $this->actingAs($this->admin, 'sanctum')->get(
            '/api/admin/stage/grille/groupe/download?type=grille_examen&groupe_id='
            . $this->groupeId . '&mode=filled'
        );

        $res->assertOk();
        $res->assertHeader('content-type', 'application/zip');
        $this->assertStringContainsString('.zip', (string) $res->headers->get('content-disposition'));

        // The archive holds one Word file per student, named after him.
        if (!class_exists('ZipArchive')) {
            Settings::setZipClass(Settings::PCLZIP);
        }

        $zip = new ZipArchive();
        $path = $res->getFile()->getPathname();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true, 'The download must be a readable zip.');

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $this->assertCount($etudiants->count(), $names);

        foreach ($etudiants as $etudiant) {
            $found = false;
            foreach ($names as $name) {
                $safe = preg_replace('~[/\\\\:*?"<>|]+~', '-', $etudiant->nom_prenom);
                if (str_contains($name, (string) $safe)) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, "No entry for {$etudiant->nom_prenom} in the archive.");
        }
    }

    public function test_a_student_gets_one_sheet_per_step_of_his_rotation(): void
    {
        $etudiant = Etudiant::with('groupe')
            ->whereHas('groupe', fn ($q) => $q->where('nom', 'Groupe A'))
            ->whereHas('groupe.niveau', fn ($q) => $q->where('numero', 3))
            ->whereHas('groupe.niveau.filiere', fn ($q) => $q->where('nom', 'Infirmier Polyvalent'))
            ->first();

        if ($etudiant === null) {
            $this->markTestSkipped('No "Infirmier Polyvalent" 3ème année group A student in the fixtures.');
        }

        // The rotation of that group, as the app reads it.
        $sections = json_decode((string) file_get_contents(database_path('data/stage_rotations.json')), true);
        $steps = [];

        foreach ((array) $sections as $section) {
            $same = mb_strtolower($section['filiere']) === 'infirmier polyvalent'
                && (int) $section['niveau'] === 3;
            if ($same) {
                $steps = (array) (($section['groupes'] ?? [])['A'] ?? []);
                break;
            }
        }

        $this->assertNotEmpty($steps, 'The IP3 group A rotation must be in stage_rotations.json.');

        $res = $this->actingAs($this->admin, 'sanctum')->get(
            '/api/admin/stage/grille/download?type=grille_evaluation&etudiant_id='
            . $etudiant->id . '&mode=blank'
        );

        $res->assertOk();

        $xml = $this->documentXml($res->getFile()->getPathname());

        // One page break between two sheets, none at the end.
        $this->assertSame(count($steps) - 1, substr_count($xml, '<w:br w:type="page"/>'));

        // Every service of the rotation is on its own sheet.
        foreach ($steps as $step) {
            $this->assertStringContainsString(
                htmlspecialchars((string) $step['service'], ENT_QUOTES | ENT_XML1, 'UTF-8'),
                $xml
            );
        }

        // The first step's place is the first step's service place.
        $this->assertStringContainsString(
            htmlspecialchars((string) $steps[0]['lieu'], ENT_QUOTES | ENT_XML1, 'UTF-8'),
            $xml
        );
    }

    public function test_the_stage_place_is_read_from_the_calendar(): void
    {
        $etudiant = Etudiant::whereHas('groupe', fn ($q) => $q->where('nom', 'Groupe A'))
            ->whereHas('groupe.niveau', fn ($q) => $q->where('numero', 3))
            ->whereHas('groupe.niveau.filiere', fn ($q) => $q->where('nom', 'Infirmier Polyvalent'))
            ->first();

        if ($etudiant === null) {
            $this->markTestSkipped('No "Infirmier Polyvalent" 3ème année group A student in the fixtures.');
        }

        $res = $this->actingAs($this->admin, 'sanctum')->get(
            '/api/admin/stage/grille/download?type=grille_examen&etudiant_id='
            . $etudiant->id . '&mode=blank'
        );

        $res->assertOk();

        // The calendar sends that group to Edderrak, in Médecine, first.
        $xml = $this->documentXml($res->getFile()->getPathname());
        $this->assertStringContainsString('Edderrak', $xml);
        $this->assertStringContainsString('Médecine', $xml);
    }
}
