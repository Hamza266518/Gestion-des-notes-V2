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
 * Diplomas are delivered as editable .docx files built server-side with PhpWord:
 * the chosen filière template is laid under an A4-landscape page and every field
 * is a floating text box, so the document can be re-touched in Word. These tests
 * pin the download endpoint to that contract.
 *
 * Cleanup deletes by primary key, like DiplomeReadinessTest, so the shared suite
 * database is never touched by predicate.
 */
class DiplomeDocxTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdEtudiants = [];
    private array $createdDiplomes = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.docx@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome docx admin',
                'email'    => 'diplome.docx@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test diplomas');
    }

    protected function tearDown(): void
    {
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
            'nom'            => 'FIL DOCX ' . $suffix,
            'nom_ar'         => 'شعبة ' . $suffix,
            'code'           => 'FD' . substr(md5($typeFormation . $suffix . uniqid()), 0, 8),
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
            'nom'                 => 'GRP DOCX ' . $suffix,
            'promotion'           => '2024/2025',
        ]);
        $this->createdGroupes[] = $groupe;

        $etudiant = Etudiant::create([
            'user_id'             => $this->admin->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'STUDENT DOCX ' . $suffix,
            'nom_ar'              => 'تلميذ',
            'cin'                 => 'DOCX' . substr(md5($suffix . uniqid('', true)), 0, 10),
            'numero_inscription'  => 'INS' . substr(md5('n' . $suffix . uniqid('', true)), 0, 14),
            'lieu_naissance'      => 'Casablanca',
            'lieu_naissance_ar'   => 'الدار البيضاء',
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

    public function test_download_serves_the_docx_with_download_headers(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'A');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->get("/api/admin/diplomes/{$diplome->id}/download");

        $res->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $res->headers->get('content-type')
        );
        $this->assertStringContainsString('Diplome_', $res->headers->get('content-disposition'));
    }

    public function test_generated_docx_is_a_valid_package_with_document_and_image_parts(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'A');
        $data = app(\App\Services\DiplomaDocxService::class)->dataFor($diplome);

        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            $bytes = file_get_contents($path);
            $this->assertStringStartsWith('PK', $bytes, 'A .docx must be a valid (zip) package');
            $this->assertGreaterThan(20000, strlen($bytes));
            $this->assertStringContainsString('word/document.xml', $bytes);
            $this->assertStringContainsString('word/media/', $bytes);
            $this->assertNotEmpty($data['nom_prenom'], 'Fixture must carry a printable name');
        } finally {
            @unlink($path);
        }
    }

    public function test_generated_docx_pins_print_layout_so_floating_objects_are_not_hidden(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'VIEW');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $settings = (string) $zip->getFromName('word/settings.xml');
            $zip->close();

            $this->assertStringContainsString(
                '<w:view w:val="print"/>',
                $settings,
                'settings.xml must pin the print view, or Draft/Web layout hides every floating object (blank diploma)'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_inject_print_view_is_inserted_once_and_never_duplicated(): void
    {
        $service = app(\App\Services\DiplomaDocxService::class);
        $xml = '<w:settings xmlns:w="urn:test"><w:defaultTabStop w:val="708"/></w:settings>';

        $once = $service->injectPrintView($xml);
        $this->assertStringContainsString('<w:view w:val="print"/>', $once);

        $twice = $service->injectPrintView($once);
        $this->assertSame($once, $twice, 'The view element must not be duplicated');
    }

    public function test_field_text_boxes_are_transparent_so_the_template_shows_through(): void
    {
        $service = app(\App\Services\DiplomaDocxService::class);
        $shape = '<v:shape type="#_x0000_t0202" style="width:200pt;"><v:textbox/></v:shape>';

        $xml = $service->injectTransparentTextboxes($shape);

        $this->assertStringContainsString('filled="f"', $xml);
        $this->assertStringContainsString('stroked="f"', $xml);
        $this->assertSame(
            $xml,
            $service->injectTransparentTextboxes($xml),
            'The transparent fill/border must not be duplicated'
        );
        // The background template image (type t75) must be left untouched.
        $image = '<v:shape type="#_x0000_t75" stroked="f"><v:imagedata/></v:shape>';
        $this->assertSame($image, $service->injectTransparentTextboxes($image));
    }

    public function test_built_docx_marks_every_text_box_shape_as_not_filled_and_not_stroked(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'FILL');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $boxes = preg_match_all('/<v:shape\b[^>]*type="#_x0000_t0202"[^>]*>/', $document, $matches);
            $this->assertGreaterThan(0, $boxes, 'The diploma must place the student values in text boxes');

            foreach ($matches[0] as $tag) {
                $this->assertStringContainsString(
                    'filled="f"',
                    $tag,
                    'A text box with a fill paints an opaque rectangle over the template'
                );
                $this->assertStringContainsString(
                    'stroked="f"',
                    $tag,
                    'A stroked text box draws a visible border on the template'
                );
            }
        } finally {
            @unlink($path);
        }
    }

    public function test_arabic_runs_use_the_configured_rtl_font(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'AR');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertStringContainsString(
                (string) config('diplomes.font_ar'),
                $document,
                'Arabic fields must use the configured Arabic font in the editable docx'
            );
            $this->assertStringContainsString(
                '<w:rtl',
                $document,
                'Arabic fields must render right-to-left in Word'
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The Arabic filière name is the only diploma text set in a face of its
     * own — and only for IP (Infirmier Polyvalent, filière code IP), per the
     * user's request of 2026-10-19. The French filière keeps font_fr and every
     * other Arabic field keeps font_ar; the dedicated value is nudged up by
     * font_ar_filiere_size_adjust on top of the usual ar_size_adjust.
     */
    public function test_ip_diplomas_print_the_arabic_filiere_in_the_dedicated_font_and_size(): void
    {
        // Kashida stretching rewrites the words (شـعبة) and would defeat the
        // plain substring matches below; it is not what this test is about.
        \Illuminate\Support\Facades\Config::set('diplomes.ar_kashida', 0);

        $type = 'Specialisation';
        $diplome = $this->makeDiplome($type, 'FNT');
        $filiere = $diplome->etudiant->groupe->niveau->filiere;
        $filiere->forceFill(['code' => 'IP'])->save();
        $etudiant = $diplome->etudiant;
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $frFont = (string) config('diplomes.font_fr');
            $arFont = (string) config('diplomes.font_ar');
            $filiereArFont = (string) config('diplomes.font_ar_filiere');
            $this->assertNotSame(
                $arFont,
                $filiereArFont,
                'The Arabic filière must wear a face distinct from the other Arabic fields'
            );

            // Only the text runs: the naive `<w:r>(.*?)</w:r>` also matches
            // the run wrapping each <w:pict> textbox, whose capture swallows
            // the real runs inside it (<w:txbxContent> even fools the <w:t>
            // match). Every printed run starts with its rPr, the wrapper does
            // not — and a run never nests another one, so `.*?` is safe.
            preg_match_all('#<w:r><w:rPr>(.*?)</w:r>#s', $document, $runs);
            $this->assertNotEmpty($runs[1]);

            $fontOf = function (string $run): string {
                return preg_match('#w:ascii="([^"]*)"#', $run, $m) ? $m[1] : '';
            };
            $textOf = function (string $run): string {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);

                return trim(implode('', $texts[1]));
            };

            $filiereArWord = null;   // « شعبة », first word of the Arabic filière
            $arabicNameRun = null;   // student's Arabic name: must stay on font_ar
            $filiereFrRun = null;    // first word of the French filière
            $dedicatedRuns = [];         // every run printed in the dedicated face

            foreach ($runs[1] as $run) {
                $text = $textOf($run);
                if ($text === '') {
                    continue;
                }

                if (str_contains($text, 'شعبة')) {
                    $filiereArWord = $run;
                }
                if (str_contains($text, (string) $etudiant->nom_ar)) {
                    $arabicNameRun = $run;
                }
                if (strcmp($text, 'FIL') === 0) {
                    $filiereFrRun = $run;
                }
                if ($fontOf($run) === $filiereArFont) {
                    $dedicatedRuns[] = $text;
                }
            }

            $this->assertNotNull($filiereArWord, 'The Arabic filière must be printed');
            $this->assertSame(
                $filiereArFont,
                $fontOf($filiereArWord),
                'The Arabic filière must use the dedicated font'
            );
            $this->assertStringContainsString(
                '<w:rtl',
                $filiereArWord,
                'The Arabic filière must stay right-to-left'
            );

            // Field size + ar_size_adjust, nudged up by the dedicated face's own
            // adjust; declared in half-points in the document.
            $declared = collect(config('diplomes.fields.' . $type))
                ->firstWhere('key', 'filiere_ar')['size'];
            $expected = (int) (
                $declared
                + (int) config('diplomes.ar_size_adjust')
                + (int) config('diplomes.font_ar_filiere_size_adjust')
            ) * 2;
            preg_match('#<w:sz w:val="(\d+)"#', $filiereArWord, $szMatch);
            $this->assertSame(
                (string) $expected,
                $szMatch[1] ?? '',
                'The Arabic filière must carry the dedicated face size adjust'
            );

            $this->assertNotNull($arabicNameRun, 'The Arabic student name must be printed');
            $this->assertSame(
                $arFont,
                $fontOf($arabicNameRun),
                'Only the filière switches font: every other Arabic field keeps font_ar'
            );

            $this->assertNotNull($filiereFrRun, 'The French filière must be printed');
            $this->assertSame(
                $frFont,
                $fontOf($filiereFrRun),
                'The French filière must keep the French face'
            );

            $this->assertNotEmpty($dedicatedRuns, 'The dedicated font must appear in the document');
            foreach ($dedicatedRuns as $text) {
                $this->assertContains(
                    $text,
                    ['شعبة', 'FNT'],
                    "Only the Arabic filière may use {$filiereArFont}, got « {$text} »"
                );
            }
        } finally {
            @unlink($path);
        }
    }

    /**
     * Scope proof (user request of 2026-10-19): only IP switches face. A
     * diploma for any other filière — AS (Qualification) / IA (Technicien) —
     * keeps the shared font_ar on its Arabic filière name, and never prints
     * the dedicated face anywhere.
     */
    public function test_non_ip_diplomas_keep_the_shared_arabic_font_on_the_filiere(): void
    {
        \Illuminate\Support\Facades\Config::set('diplomes.ar_kashida', 0);

        // makeDiplome() gives the filière a generated code (never 'IP').
        $diplome = $this->makeDiplome('Technicien', 'NIP');
        $this->assertNotContains(
            (string) $diplome->etudiant->groupe->niveau->filiere->code,
            (array) config('diplomes.font_ar_filiere_codes')
        );

        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $arFont = (string) config('diplomes.font_ar');
            $filiereArFont = (string) config('diplomes.font_ar_filiere');

            preg_match_all('#<w:r><w:rPr>(.*?)</w:r>#s', $document, $runs);
            $this->assertNotEmpty($runs[1]);

            $found = 0;
            foreach ($runs[1] as $run) {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);
                $text = trim(implode('', $texts[1]));
                if ($text === '') {
                    continue;
                }

                preg_match('#w:ascii="([^"]*)"#', $run, $fontMatch);
                $font = $fontMatch[1] ?? '';

                $this->assertNotSame(
                    $filiereArFont,
                    $font,
                    "A non-IP diploma must never use the dedicated face (« {$text} »)"
                );

                if (str_contains($text, 'شعبة')) {
                    $found++;
                    $this->assertSame(
                        $arFont,
                        $font,
                        'The Arabic filière of a non-IP diploma keeps font_ar'
                    );
                }
            }

            $this->assertSame(1, $found, 'The Arabic filière must be printed once');
        } finally {
            @unlink($path);
        }
    }

    /**
     * « Letters set closer » (user request of 2026-10-19): the IP Arabic
     * filière is printed WITHOUT the kashida stretching — no tatweel bars
     * pulling its letters apart — while every other Arabic value keeps the
     * template's stretched look.
     */
    public function test_ip_arabic_filiere_keeps_its_letters_close_together(): void
    {
        // Unlike the two font tests (which disable kashida to match plain
        // words), this one runs with the shipped default so the stretch is on.
        $this->assertGreaterThan(0, (int) config('diplomes.ar_kashida'));

        $diplome = $this->makeDiplome('Specialisation', 'CLS');
        $filiere = $diplome->etudiant->groupe->niveau->filiere;
        // The real IP value, the one the user reported: ممرض متعدد الاختصاصات.
        $filiere->forceFill(['code' => 'IP', 'nom_ar' => 'شعبة الاختصاصات'])->save();

        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            preg_match_all('#<w:r><w:rPr>(.*?)</w:r>#s', $document, $runs);
            $this->assertNotEmpty($runs[1]);

            $tatweel = 'ـ'; // ـ U+0640, the stretching bar
            $filiereArFont = (string) config('diplomes.font_ar_filiere');
            $dedicatedRuns = 0;
            $stretchedRuns = 0;

            foreach ($runs[1] as $run) {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);
                $text = trim(implode('', $texts[1]));
                if ($text === '') {
                    continue;
                }

                preg_match('#w:ascii="([^"]*)"#', $run, $fontMatch);
                $font = $fontMatch[1] ?? '';

                if ($font === $filiereArFont) {
                    $dedicatedRuns++;
                    $this->assertStringNotContainsString(
                        $tatweel,
                        $text,
                        "The IP Arabic filière must keep its letters close (« {$text} »)"
                    );
                }

                if (str_contains($text, $tatweel)) {
                    $stretchedRuns++;
                }
            }

            $this->assertSame(
                2,
                $dedicatedRuns,
                'The two Arabic filière words (شعبة الاختصاصات) must use the dedicated face'
            );
            $this->assertGreaterThan(
                0,
                $stretchedRuns,
                'Every other Arabic value keeps the template stretch'
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * The institute name is centered in its box — in French and Arabic alike —
     * instead of being aligned to the left (French) / right (Arabic), per the
     * user's request of 2026-10-19. The other information values keep their side
     * alignment, so only the two institute lines change.
     */
    public function test_institute_name_is_centered_on_both_halves(): void
    {
        $service = app(\App\Services\DiplomaDocxService::class);
        // The parameter row is shared across the suite database: remember what it
        // held so the test can put it back.
        $before = $service->informations();

        $service->saveInformations([
            'institut_fr' => 'INSTITUT-FR-CENTER',
            'institut_ar' => 'معهد-الاختبار',
            'ville_fr'    => 'VILLE-FR-LEFT',
            'ville_ar'    => 'مدينة-الاختبار',
        ]);

        // Plain words so the Arabic values read back exactly as typed.
        \Illuminate\Support\Facades\Config::set('diplomes.ar_kashida', 0);

        $diplome = $this->makeDiplome('Technicien', 'CTR');
        $path = $service->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            // Every value lives in its own transparent text box; take each box
            // whole so the paragraph's <w:jc> can be read next to the text.
            preg_match_all('#<w:txbxContent>(.*?)</w:txbxContent>#s', $document, $boxes);
            $this->assertNotEmpty($boxes[1]);

            $boxOf = function (string $needle) use ($boxes): ?string {
                foreach ($boxes[1] as $box) {
                    if (str_contains($box, $needle)) {
                        return $box;
                    }
                }

                return null;
            };

            foreach (['INSTITUT-FR-CENTER', 'معهد-الاختبار'] as $needle) {
                $box = $boxOf($needle);
                $this->assertNotNull($box, "The value « {$needle} » must be printed");
                $this->assertStringContainsString(
                    '<w:jc w:val="center"/>',
                    $box,
                    "The institute name « {$needle} » must be centered"
                );
            }

            $frCity = $boxOf('VILLE-FR-LEFT');
            $this->assertNotNull($frCity, 'The French city must be printed');
            $this->assertStringContainsString(
                '<w:jc w:val="left"/>',
                $frCity,
                'Other French values stay left-aligned'
            );

            $arCity = $boxOf('مدينة-الاختبار');
            $this->assertNotNull($arCity, 'The Arabic city must be printed');
            $this->assertStringContainsString(
                '<w:jc w:val="right"/>',
                $arCity,
                'Other Arabic values stay right-aligned'
            );
        } finally {
            $service->saveInformations($before);
            @unlink($path);
        }
    }

    public function test_identifiers_keep_the_french_face_and_size_on_the_arabic_half(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'MIX');
        $etudiant = $diplome->etudiant;
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $frFont = (string) config('diplomes.font_fr');

            preg_match_all('#<w:r>(.*?)</w:r>#s', $document, $runs);
            $this->assertNotEmpty($runs[1]);

            $seen = ['cin' => 0, 'numero_inscription' => 0];
            foreach ($runs[1] as $run) {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);
                $text = implode('', $texts[1]);
                if ($text === '') {
                    continue;
                }

                // CIN and inscription number are identifiers: even on the Arabic
                // half they must keep the French face and size instead of being
                // enlarged and re-glyphed by the Arabic face.
                $target = null;
                if (str_contains($text, (string) $etudiant->cin)) {
                    $target = 'cin';
                } elseif (str_contains($text, (string) $etudiant->numero_inscription)) {
                    $target = 'numero_inscription';
                }
                if ($target === null) {
                    continue;
                }

                preg_match('#w:ascii="([^"]*)"#', $run, $fontMatch);
                $this->assertSame($frFont, $fontMatch[1] ?? '', "The {$target} '{$text}' must use the French face on both halves");

                // The Arabic face would have added `ar_size_adjust` points; an
                // identifier must keep the plain French size instead, so the
                // expectation is read from the config rather than hardcoded.
                $declared = collect(config('diplomes.fields.Technicien'))
                    ->firstWhere('key', $target)['size'];
                preg_match('#<w:sz w:val="(\d+)"#', $run, $szMatch);
                $this->assertSame((string) ($declared * 2), $szMatch[1] ?? '', "The {$target} must keep the French size");

                $seen[$target]++;
            }

            $this->assertSame(2, $seen['cin'], 'The CIN must be printed on both halves');
            $this->assertSame(2, $seen['numero_inscription'], 'The inscription number must be printed on both halves');
        } finally {
            @unlink($path);
        }
    }

    public function test_identifiers_with_arabic_indic_digits_stay_on_the_french_face(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'ARIND');
        $diplome->etudiant->forceFill(['cin_ar' => 'IP٢٠٠٠١٨'])->save();

        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $frFont = (string) config('diplomes.font_fr');

            preg_match_all('#<w:r>(.*?)</w:r>#s', $document, $runs);
            $found = 0;
            foreach ($runs[1] as $run) {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);
                $text = implode('', $texts[1]);
                if (! str_contains($text, 'IP٢٠٠٠١٨')) {
                    continue;
                }

                // Arabic-Indic digits are still an identifier: keep the French
                // face and size instead of being enlarged by the Arabic face.
                preg_match('#w:ascii="([^"]*)"#', $run, $fontMatch);
                $this->assertSame($frFont, $fontMatch[1] ?? '', 'The CIN must use the French face');
                $declared = collect(config('diplomes.fields.Technicien'))
                    ->firstWhere('key', 'cin')['size'];
                preg_match('#<w:sz w:val="(\d+)"#', $run, $szMatch);
                $this->assertSame((string) ($declared * 2), $szMatch[1] ?? '', 'The CIN must keep the French size');
                $found++;
            }

            $this->assertSame(1, $found, 'The Arabic-Indic CIN must be printed on the Arabic half');
        } finally {
            @unlink($path);
        }
    }

    public function test_place_of_birth_is_printed_in_capitals(): void
    {
        // makeDiplome stores "Casablanca"; the diploma must print "CASABLANCA".
        $diplome = $this->makeDiplome('Technicien', 'CAP');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            $this->assertStringContainsString('CASABLANCA', $document);
            $this->assertStringNotContainsString('>Casablanca<', $document);
        } finally {
            @unlink($path);
        }
    }

    public function test_arabic_birth_date_and_place_are_bold(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'ARBOLD');
        $diplome->etudiant->forceFill([
            'date_naissance'    => '1998-03-23',
            'date_naissance_ar' => null,
            'lieu_naissance_ar' => 'مراكش',
        ])->save();

        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            preg_match_all('#<w:r>(.*?)</w:r>#s', $document, $runs);
            $this->assertNotEmpty($runs[1]);

            $seen = ['date' => 0, 'place' => 0];
            foreach ($runs[1] as $run) {
                preg_match_all('#<w:t[^>]*>(.*?)</w:t>#s', $run, $texts);
                // The Arabic value is stretched with kashida (tatweel) gaps, so
                // strip tatweels before matching the place name.
                $text = str_replace("\u{0640}", '', implode('', $texts[1]));
                if ($text === '') {
                    continue;
                }

                if (preg_match('#23[/\.-]03[/\.-]1998#', $text)) {
                    $target = 'date';
                } elseif (str_contains($text, 'مراكش')) {
                    $target = 'place';
                } else {
                    continue;
                }

                $this->assertSame(1, preg_match('#<w:b[ />]#', $run), "The Arabic {$target} must be bold");
                $this->assertSame(1, preg_match('#<w:bCs[ />]#', $run), "The Arabic {$target} must be bold for complex scripts");
                $seen[$target]++;
            }

            $this->assertGreaterThanOrEqual(1, $seen['date'], 'The Arabic birth date must be printed on the Arabic half');
            $this->assertGreaterThanOrEqual(1, $seen['place'], 'The Arabic place of birth must be printed on the Arabic half');
        } finally {
            @unlink($path);
        }
    }

    public function test_each_template_type_has_calibration_over_the_same_field_set(): void
    {
        $fields = config('diplomes.fields');

        $this->assertEqualsCanonicalizing(
            ['Qualification', 'Technicien', 'Specialisation'],
            array_keys($fields)
        );

        $keysByType = array_map(fn ($list) => array_column($list, 'key'), $fields);
        foreach ($keysByType as $type => $keys) {
            $this->assertSame(
                $keysByType['Qualification'],
                $keys,
                "Type {$type} must calibrate the same field set as Qualification"
            );
            $this->assertNotContains(
                'date_inscription_ar',
                $keys,
                'The Arabic inscription date was removed from the calibrage'
            );
        }
    }

    public function test_every_type_formation_generates_a_valid_docx(): void
    {
        $service = app(\App\Services\DiplomaDocxService::class);

        foreach (['Qualification', 'Technicien', 'Specialisation'] as $type) {
            $diplome = $this->makeDiplome($type, $type);
            $path = $service->build($diplome);

            try {
                $this->assertStringStartsWith(
                    'PK',
                    file_get_contents($path),
                    "Type {$type} must produce a valid .docx"
                );
            } finally {
                @unlink($path);
            }
        }
    }

    public function test_page_is_true_a4_landscape_not_a_rotated_portrait(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'LANDS');
        $phpWord = app(\App\Services\DiplomaDocxService::class)->buildPhpWord($diplome);

        $style = $phpWord->getSections()[0]->getStyle();

        // Regression: orientation was applied BEFORE paperSize, so setOrientation()
        // swapped the default sides and setPaperSize('A4') then reset them to the
        // portrait dimensions -- a page Word read as 210mm wide and clipped every
        // field past it. A true landscape page must be wider than tall.
        $this->assertSame('landscape', $style->getOrientation());
        $this->assertGreaterThan(
            $style->getPageSizeH(),
            $style->getPageSizeW(),
            'The A4 page must be landscape (width > height), or Word clips the right edge'
        );
        // A4 landscape = 16838 x 11906 twips.
        $this->assertEquals(16838, (int) round($style->getPageSizeW()));
        $this->assertEquals(11906, (int) round($style->getPageSizeH()));

        // No header/footer text exists, so no header/footer distance may
        // reserve page height the full-page artwork needs.
        $this->assertEquals(0, $style->getHeaderHeight());
        $this->assertEquals(0, $style->getFooterHeight());
    }

    public function test_floating_shapes_are_collapsed_so_the_diploma_stays_on_one_page(): void
    {
        $diplome = $this->makeDiplome('Technicien', 'ONEPAGE');
        $path = app(\App\Services\DiplomaDocxService::class)->build($diplome);

        try {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
            $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
            $zip->open($path);
            $document = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            // The template raster and every value box are floating VML shapes,
            // each in its own paragraph.
            $shapes = substr_count($document, '<v:shape ');
            $this->assertGreaterThan(1, $shapes);

            // Word lays each of those paragraphs out as one empty text line, so
            // ~50 boxes overflow the 595pt page and silently add a second sheet.
            // Every shape paragraph must be pinned to a 1pt exact line (the
            // shapes are page-anchored, so nothing moves); only the trailing
            // paragraph may keep a normal line height.
            $this->assertSame(
                $shapes,
                substr_count($document, 'w:lineRule="exact"'),
                'Every floating-shape paragraph must be collapsed or the diploma spans two pages'
            );
            $this->assertSame(
                1,
                substr_count($document, '<w:p><w:pPr/>'),
                'Only the trailing paragraph may keep a normal line height'
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_templates_meta_exposes_templates_and_calibrated_fields(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/diplomes/templates-meta');

        $res->assertOk();
        $data = $res->json('data');

        $this->assertEquals(297, round($data['page_width_mm']));
        $this->assertEquals(210, $data['page_height_mm']);

        $types = array_map(fn ($t) => $t['type'], $data['templates']);
        $this->assertEquals(['Qualification', 'Technicien', 'Specialisation'], $types);

        foreach ($data['templates'] as $template) {
            $this->assertNotEmpty($template['template_url']);
            $this->assertNotEmpty($template['fields']);
            foreach ($template['fields'] as $field) {
                $this->assertArrayHasKey('key', $field);
                $this->assertArrayHasKey('x', $field);
                $this->assertArrayHasKey('y', $field);
            }
        }
    }
}