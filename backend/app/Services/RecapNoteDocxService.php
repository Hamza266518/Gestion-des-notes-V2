<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Builds the editable "relevé des notes" jury sheet as a .docx, styled after the
 * reference recap document ("1 récap des notes IP 3 eme 2024 2025.docx"):
 *
 *  - A4 landscape with the institute logo and the "Etablissement privé autorisé
 *    par l'État" line in the page header, and the institute address / contacts /
 *    website in the footer (legacy wording kept verbatim from the reference).
 *  - A jury table (date/lieu, "Membre de jury présent" and the three member
 *    columns, left blank for handwriting), a filière/section table, the grade
 *    grid (title, subject headers, coefficients, students, class average) and
 *    the signature block.
 *
 * The output stays a plain table layout (no floating boxes), so Word reflows it
 * naturally while printing.
 */
class RecapNoteDocxService
{
    // Reference recap table width in twips; the page margins leave exactly this
    // much usable width (A4 landscape 16838 twips − 2 × 696).
    private const TABLE_W = 15446;

    // Fixed columns of the grade grid (twips): student name, average, observation.
    private const COL_NAME = 1952;
    private const COL_AVG = 1136;
    private const COL_OBS = 1958;

    // The Stage sheet has no average column: a wide name column, the two stage
    // marks and the wide proposition column, reproduced from the reference stage
    // page (grid 4113 / 2432 / 2855 / 6192 twips scaled to our usable width).
    // The two marks share the middle width equally.
    private const STAGE_NAME_W = 4074;
    private const STAGE_OBS_W = 6135;

    // The synthesis sheet keeps an average column (NF) and a proposition column,
    // taken from the reference synthesis page (name 2518, NF 2333, proposition
    // 2876 twips); the four marks share the remaining width.
    private const SYNTHESE_NAME_W = 2518;
    private const SYNTHESE_AVG_W = 2333;
    private const SYNTHESE_OBS_W = 2876;

    // Signature block columns, taken from the reference sheet (its own grid, not
    // the grade table's): boundaries 32/176/368/548/810 pt over 778 pt -> the
    // four centred "Signature ..." labels each fit on a single line.
    private const SIG_W = [2865, 3800, 3574, 5207];

    private const FONT = 'Century Gothic';
    private const GRAY = 'F2F2F2';

    /**
     * Institute banner size (pt) of the page header, matching the reference sheet
     * (263 x 29 pt). It sits on its own line above the authorization line.
     */
    private const LOGO_W = 263;
    private const LOGO_H = 29;

    /**
     * Distance (cm) from the page top edge to the header content. The reference
     * starts its banner ~14 pt down, so a small value keeps the header high on the
     * page and leaves no blank band above it.
     */
    private const HEADER_DISTANCE_CM = 0.55;

    /**
     * Twips kept between the bottom of the signature table and the body area
     * floor, so the signing strip can expand to fill the sheet without ever
     * touching the footer.
     */
    private const SIGN_BOTTOM_RESERVE = 260;

    /**
     * Baseline student-row pitch (twips) of the recap's tight line (~9.3pt). Word
     * renders our single-line rows a touch taller, so the sheet is laid out on the
     * pitch we actually measure and the free height above the signing strip is
     * shared out over the rows (see MAX_STUDENT_ROW), which keeps a sheet whose
     * fixed blocks are short from being left half empty. A row only grows further,
     * to STUDENT_WRAP_LINE per line, when a name wraps.
     */
    private const STUDENT_ROW = 200;
    private const STUDENT_WRAP_LINE = 169;

    /**
     * Ceiling (twips) of the data-row pitch. Whatever the fixed blocks leave free
     * is shared out over the student rows so a sheet whose fixed content is short
     * (Stage, synthesis) grows its rows instead of leaving a large blank signing
     * strip; the cap keeps the rows from ever looking loose.
     */
    private const MAX_STUDENT_ROW = 240;

    /**
     * Minimum height (twips) the data rows must leave for the signing strip. The
     * rows stretch to consume everything above it, so every sheet ends with the
     * same small signing strip instead of a large blank band.
     */
    private const SIGN_STRIP_TARGET = 300;

    /**
     * Student-row pitch (twips) of the NSTI sheet. Its header row carries the ten
     * long jury criteria, so it wraps to several lines and would otherwise force
     * `sizeToFit` to shrink every font on the sheet (the criterion names dropped
     * to ~4.5pt). The reference NSTI page runs its data rows at the same tight
     * ~8pt pitch, which frees exactly the room the tall header needs and keeps
     * the sheet one page — and its fonts identical to the other tables.
     */
    private const NSTI_STUDENT_ROW = 160;

    /**
     * The recap tables of a filière, in the order the page's selector lists
     * them. The "download all" path renders one sheet per entry, in this order.
     */
    public const TABLE_TYPES = [
        'mpcc1',
        'mpcc2',
        'mpcc_global',
        'mpefcf1',
        'mpefcf2',
        'mpefcf_global',
        'mpefcfp1',
        'mpefcfp2',
        'mpefcfp_global',
        'nsti',
        'stage',
        'synthese',
    ];

    public function build(array $data): string
    {
        return $this->save($this->buildPhpWord($data));
    }

    /**
     * Every table of a filière in a single .docx: each dataset is rendered as
     * its own A4-landscape section, so every table starts on a fresh page just
     * like the single-table document.
     */
    public function buildAll(array $datasets): string
    {
        return $this->save($this->buildAllPhpWord($datasets));
    }

    private function save(PhpWord $phpWord): string
    {
        if (!class_exists('ZipArchive')) {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'releve_');
        $writer->save($tmp);

        return $tmp;
    }

    public function buildPhpWord(array $data): PhpWord
    {
        $phpWord = $this->newPhpWord();
        $this->addSheet($phpWord, $data);

        return $phpWord;
    }

    public function buildAllPhpWord(array $datasets): PhpWord
    {
        $phpWord = $this->newPhpWord();
        foreach ($datasets as $data) {
            $this->addSheet($phpWord, $data);
        }

        return $phpWord;
    }

    private function newPhpWord(): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName(self::FONT);
        // Paragraph marks default to this size: keeping it small avoids the
        // ~9.76pt floor every table row inherits from an 8pt mark. Every run
        // below passes its own explicit 'size', so this only affects marks.
        $phpWord->setDefaultFontSize(7);

        return $phpWord;
    }

    /**
     * One recap sheet: a landscape section carrying the header, footer, jury,
     * filière, grade and signature tables. Called once per page, whether the
     * document holds a single table or every table of the filière.
     */
    private function addSheet(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection([
            'orientation' => 'landscape',
            'pageSizeW' => Converter::cmToTwip(29.7),
            'pageSizeH' => Converter::cmToTwip(21.0),
            'marginTop' => Converter::cmToTwip(0),
            'marginBottom' => Converter::cmToTwip(0.25),
            'marginLeft' => 696,
            'marginRight' => 696,
            'headerHeight' => Converter::cmToTwip(self::HEADER_DISTANCE_CM),
            'footerHeight' => Converter::cmToTwip(0.5),
        ]);

        $this->addHeader($section);
        $this->addFooter($section);

        $layout = $this->sizeToFit($data);

        $this->addJuryTable($section, $layout, $data['subjects'] ?? []);
        $this->addFiliereTable($section, $data['header'], $layout, $data['subjects'] ?? []);
        $this->addGradeTable($section, $data, $layout);
        $this->addSignatureTable($section, $layout, $data['subjects'] ?? []);
    }

    /**
     * Compute the scale that makes the whole sheet fit exactly one page. All
     * sizes are described in design points; the returned 'scale' shrinks them
     * until the estimated (wrap-aware) height of every block fits the printable
     * area. The data rows then stretch over the height the fixed blocks leave
     * free, up to MAX_STUDENT_ROW, and the signature signing strip keeps
     * SIGN_STRIP_TARGET: every sheet ends just above the footer, filled to the
     * bottom, with the same compact signing strip instead of a large blank band.
     */
    private function sizeToFit(array $data): array
    {
        // Measured (Word / PDF) height of the usable body area in twips: from
        // the first line under the header down to the footer frame. The footer
        // text starts at 561.8pt on a 595.2pt page, the title top is at 57.8pt.
        $bodyAreaH = 10272;

        // Word always keeps a paragraph after the last table; reserve its line plus
        // the slice Word draws past the last row as part of the table box.
        $trailing = 290;

        // Minimum usable strip left for signatures. Everything the fixed blocks
        // leave above it is handed to the data rows, so a sheet with short fixed
        // content is not left with a large blank signing strip.
        $minBlank = self::SIGN_STRIP_TARGET;

        $scale = 1.0;
        for ($i = 0; $i < 200; $i++) {
            if ($this->fitsHeight($data, $scale, $bodyAreaH, $trailing, $minBlank)) {
                break;
            }
            $scale = max(0.5, $scale - 0.005);
            if ($scale <= 0.5) {
                break;
            }
        }
        // small margin so atLeast rows never push the trailing paragraph over
        $scale = min(1.0, $scale * 0.985);

        $count = count($data['students'] ?? []);
        $nonStudent = $this->estimateHeight($data, $scale);

        // Longest student name, in wrapped lines at the design size.
        $maxNameLines = 1;
        foreach ($data['students'] as $student) {
            $maxNameLines = max($maxNameLines, $this->wrappedLines(
                (string) ($student['nom_prenom'] ?? ''),
                $this::COL_NAME - 216,
                6.0
            ));
        }
        // Stretch the data rows over the height the fixed blocks leave free (minus
        // the signing strip) instead of dumping all of it into the signature
        // table. The pitch never drops below the reference slack row and is capped
        // so the rows stay tight.
        $baseRow = $this->studentRowFor($data);
        $room = max(0, $bodyAreaH - $nonStudent - $trailing - $minBlank - self::SIGN_BOTTOM_RESERVE);
        $rowsFit = $count > 0 ? (int) floor($room / $count) : $baseRow;
        $studentH = (int) round(max(
            max($baseRow, min(self::MAX_STUDENT_ROW, $rowsFit)),
            $maxNameLines * self::STUDENT_WRAP_LINE
        ));

        $studentBlock = $count * $studentH;
        // The signing strip fills whatever height the student rows left free, so
        // the signature table always ends just above the footer, exactly like the
        // reference sheet. The reserve keeps it clear of the footer for every
        // variant (including the single-block global tables); SIGN_STRIP_TARGET is
        // the floor, so a row-pitch cap can never shrink the strip below signing
        // height.
        $signatureBlank = max(
            $minBlank,
            $bodyAreaH - $studentBlock - $nonStudent - $trailing - self::SIGN_BOTTOM_RESERVE
        );

        return [
            'scale' => $scale,
            'student_h' => $studentH,
            'name_font' => $this->nameFontFor($data, 7.0),
            'signature_blank' => $signatureBlank,
        ];
    }

    /**
     * Whether the fixed blocks, the student rows, the signature strip and the
     * trailing paragraph together stay inside the printable body area for the
     * given scale.
     */
    private function fitsHeight(array $data, float $scale, int $bodyAreaH, int $trailing, int $minBlank): bool
    {
        $students = count($data['students'] ?? []);
        $nonStudent = $this->estimateHeight($data, $scale);

        return $nonStudent + $students * $this->studentRowFor($data) + $trailing + $minBlank <= $bodyAreaH;
    }

    /**
     * Data-row pitch (twips) for a sheet: the standard slack row, except the
     * NSTI sheet whose tall criterion header needs the tighter reference pitch.
     */
    private function studentRowFor(array $data): int
    {
        return ($data['table_type'] ?? '') === 'nsti' ? self::NSTI_STUDENT_ROW : self::STUDENT_ROW;
    }

    /**
     * Font that keeps the longest student name of the sheet on a single line
     * inside the name column (1856 twips usable at design size).
     */
    private function nameFontFor(array $data, float $studentFont): float
    {
        $maxLen = 0;
        foreach ($data['students'] ?? [] as $student) {
            $maxLen = max($maxLen, mb_strlen((string) ($student['nom_prenom'] ?? '')));
        }
        if ($maxLen <= 0) {
            return $studentFont;
        }
        $byWidth = self::COL_NAME - 20 - 76;          // side margins + vAlign breathing
        $fit = $byWidth / ($maxLen * 0.55 * 20);
        return max(5.0, min($studentFont, $fit));
    }

    /**
     * Average line height (twips) of a text of the given point size: Word
     * renders Century Gothic with roughly a 1.25 em line box.
     */
    private function lineTw(float $pt): int
    {
        return (int) round($pt * 1.25 * 20);
    }

    /**
     * Point size of the grade-header labels (unit names, "Nom et Prénom",
     * "Observation des formateurs", the average header). The reference sheet
     * uses 8pt for the 5-column BLOC1 page and 6pt for the 10-column fin-cursus
     * page, so it visibly shrinks the header as the unit count grows. We follow
     * the same rule (8pt for up to 5 units, -0.4pt per extra unit, floored so a
     * sheet with many units still fits one page like every other variant).
     */
    private function hdrPtFor(int $n): float
    {
        return max(5.0, min(8.0, 8.0 - 0.4 * max(0, $n - 5)));
    }

    /**
     * Estimated number of wrapped lines a text takes inside a cell of the given
     * usable width (twips) at the given point size. One extra line is added
     * whenever text wraps, so the computed height stays above Word's real
     * glyph metrics instead of below them.
     */
    private function wrappedLines(string $text, int $usableW, float $pt): int
    {
        $charW = $pt * 0.55 * 20;
        $perLine = max(1, (int) floor($usableW / max(1.0, $charW)));
        $len = mb_strlen($text);
        if ($len <= 0) {
            return 1;
        }
        $lines = (int) ceil($len / $perLine);
        return $lines > 1 ? $lines + 1 : 1;
    }

    /**
     * Estimated height (twips) of every fixed block above the student rows for a
     * given scale: jury, filière, grade title, coefficient row, wrapped unit row,
     * class average and signature label. The estimate leans a little high so the
     * computed signature strip never pushes the sheet onto a second page.
     */
    private function estimateHeight(array $data, float $s): int
    {
        $subjects = $data['subjects'] ?? [];
        $n = count($subjects);
        $geo = $this->gradeGeometry($data);
        $subjW = $geo['subj_w'];

        // Every block above the student rows uses an explicit row height, so it
        // does not scale with the fit: jury 3 rows (240/240/930), filière 2 rows
        // (250/250), grade title (260), coefficient (200), class average (260)
        // and signature label (280). The Stage and synthesis sheets drop the
        // class-average row; the synthesis also has no title row.
        $h = 240 + 240 + 930;
        $h += 250 + 250;
        if ($geo['has_title']) {
            $h += 260;
        }
        $h += 200;
        if ($geo['has_class_average']) {
            $h += 260;
        }
        $h += 280;

        // Only the unit-name row grows: it wraps to as many lines as the longest
        // label needs at the header size chosen for this subject count.
        $hdrPt = $this->hdrPtFor($n);
        $unitLines = 1;
        foreach ($subjects as $subject) {
            $unitLines = max($unitLines, $this->wrappedLines((string) $subject['nom'], $subjW - 216, $hdrPt * $s));
        }
        $h += max(200, $this->lineTw($hdrPt * $s) * $unitLines);

        // Word's real rendering of these rows is a touch taller than the sum of
        // the explicit heights (borders + line boxes); over-estimate rather than
        // under-estimate so the sheet can never spill to a second page.
        return (int) ceil($h * 1.04);
    }

    /**
     * Geometry of the grade grid for a sheet: how wide each column is and which
     * blocks it renders. The plain tables carry a title row over the units, an
     * average column and a class-average row. The Stage sheet has no average
     * column (its title spans the whole width, like the reference stage page)
     * and the synthesis sheet has no title row; neither of them prints a
     * class-average row, exactly like the reference.
     *
     * @return array{name_w:int, subj_w:int, avg_w:int, obs_w:int, has_title:bool, banner:bool, has_average:bool, has_class_average:bool}
     */
    private function gradeGeometry(array $data): array
    {
        $type = $data['table_type'] ?? 'mpcc1';
        $n = max(1, count($data['subjects'] ?? []));

        if ($type === 'stage') {
            return [
                'name_w' => self::STAGE_NAME_W,
                'subj_w' => (int) ((self::TABLE_W - self::STAGE_NAME_W - self::STAGE_OBS_W) / $n),
                'avg_w' => 0,
                'obs_w' => self::STAGE_OBS_W,
                'has_title' => true,
                'banner' => true,
                'has_average' => false,
                'has_class_average' => false,
            ];
        }

        if ($type === 'synthese') {
            return [
                'name_w' => self::SYNTHESE_NAME_W,
                'subj_w' => (int) ((self::TABLE_W - self::SYNTHESE_NAME_W - self::SYNTHESE_AVG_W - self::SYNTHESE_OBS_W) / $n),
                'avg_w' => self::SYNTHESE_AVG_W,
                'obs_w' => self::SYNTHESE_OBS_W,
                'has_title' => false,
                'banner' => false,
                'has_average' => true,
                'has_class_average' => false,
            ];
        }

        return [
            'name_w' => self::COL_NAME,
            'subj_w' => (int) ((self::TABLE_W - self::COL_NAME - self::COL_AVG - self::COL_OBS) / $n),
            'avg_w' => self::COL_AVG,
            'obs_w' => self::COL_OBS,
            'has_title' => true,
            'banner' => false,
            'has_average' => true,
            'has_class_average' => true,
        ];
    }

    /**
     * Design point size scaled down for the chosen fit, rounded to half points.
     */
    private function sz(float $pt, array $layout): float
    {
        return round($pt * $layout['scale'] * 2) / 2;
    }

    /**
     * Header faithful to the reference sheet: the institute banner sits on its own
     * line and the authorization line runs bold just under it, so the logo and the
     * text never overlap (the reference places the text at the banner's lower edge,
     * 263 x 29 pt logo above a single 7 pt line).
     */
    private function addHeader($section): void
    {
        $header = $section->addHeader();
        $logo = public_path('images/institut_logo.png');

        if (is_file($logo)) {
            // Layout image: inline, so it takes its own line's height and pushes
            // the authorization line below it instead of printing over it.
            $header->addTextRun($this->para())->addImage($logo, [
                'width' => self::LOGO_W,
                'height' => self::LOGO_H,
            ]);
        }

        $header->addText(
            "Etablissement privé autorisé par L'État le : 12/01/2022 sous le N° :6/05/1/2022",
            ['bold' => true, 'size' => 7, 'name' => self::FONT],
            [...$this->para(), 'alignment' => Jc::LEFT]
        );
    }

    /**
     * Footer: the institute address / contacts, exactly as in the reference.
     */
    private function addFooter($section): void
    {
        $footer = $section->addFooter();

        $run = $footer->addTextRun([...$this->para(), 'alignment' => Jc::CENTER]);
        $run->addText(
            'Adresse : Immeuble N° :25 Avenue Al Bakay Lhbil Berkane, Tel Fixe : 05 36 61 10 10, '
            . 'Tel Mobile : 06 61 61 27 67, Adresse Mail : ifpberkane@gmail.com,Site Web : ',
            ['size' => 7.6, 'name' => self::FONT]
        );
        $run->addText('www.institutifp.com', [
            'size' => 7.6,
            'name' => self::FONT,
            'color' => '0070C0',
        ]);
    }

    /**
     * Jury table: date/lieu, the "Membre de jury présent" banner and the three
     * member columns. The boundaries and wording are copied from the reference
     * band (5354 / 6073 / 4019 twips), which keeps the "Membre représentant de
     * la Formation Professionnelle" cell on one line and leaves no empty column
     * between the member cells. Names are intentionally left blank for
     * handwriting.
     */
    private function addJuryTable($section, array $layout, array $subjects): void
    {
        $w = [5354, 6073, 4019];

        $table = $section->addTable($this->tableStyle(108));

        // Row 1: Date / Lieu - the date is written by hand, no placeholder.
        $table->addRow(240, ['height' => 240]);
        $table->addCell($w[0], ['vAlign' => 'center'])->addText(
            'Date : ',
            ['bold' => true, 'size' => $this->sz(7, $layout)],
            $this->para(['alignment' => Jc::CENTER])
        );

        $cell = $table->addCell($w[1] + $w[2], ['gridSpan' => 2, 'vAlign' => 'center']);
        $run = $cell->addTextRun($this->para());
        $run->addText('Lieu', ['bold' => true, 'size' => $this->sz(8, $layout)]);
        $run->addText(' : Institut des formations paramédicales privé', ['size' => $this->sz(8, $layout)]);

        // Row 2: banner
        $table->addRow(240, ['height' => 240]);
        $table->addCell($w[0] + $w[1] + $w[2], ['gridSpan' => 3, 'vAlign' => 'center'])->addText(
            'Membre de jury présent',
            ['bold' => true, 'size' => $this->sz(8, $layout)],
            $this->para(['alignment' => Jc::CENTER])
        );

        // Row 3: members (labels bold, blanks plain) - the reference leaves the
        // space empty with no underline, handwriting shows through.
        $table->addRow(930, ['height' => 930]);
        $this->juryMemberCell($table->addCell($w[0], ['vAlign' => 'top']), $layout, [
            'Membres professionnels :',
            'Président de jury :',
            '',
            'Membres professionnels :',
            '',
        ]);
        $this->juryMemberCell($table->addCell($w[1], ['vAlign' => 'top']), $layout, [
            "Membre de l'établissement :",
            'Formateur permanent :',
            '',
            'Membres interne :',
            '',
        ]);
        $this->juryMemberCell($table->addCell($w[2], ['vAlign' => 'top']), $layout, [
            'Membre représentant de la Formation Professionnelle :',
        ]);
    }

    /**
     * One column of the jury table: each line is a single bold label; the space
     * below stays blank so a name can be handwritten (reference has no lines).
     */
    private function juryMemberCell($cell, array $layout, array $lines): void
    {
        foreach ($lines as $label) {
            $cell->addText($label, ['bold' => true, 'size' => $this->sz(6, $layout)], $this->para());
        }
    }

    /**
     * Filière / section band: a two-column table exactly like the reference
     * (column ratio 7814:7632), each cell carrying "Label : value" (bold label,
     * regular value, 9pt).
     */
    private function addFiliereTable($section, array $header, array $layout, array $subjects): void
    {
        $leftW = (int) round($this::TABLE_W * 7814 / 15446);
        $rightW = $this::TABLE_W - $leftW;

        $table = $section->addTable($this->tableStyle(108));

        $table->addRow(250, ['height' => 250]);
        $this->filiereCell($table->addCell($leftW, ['vAlign' => 'center']), $layout, 'Filière', $header['filiere'] ?? '');
        $this->filiereCell($table->addCell($rightW, ['vAlign' => 'center']), $layout, 'Année Scolaire', $header['annee_scolaire'] ?? '');

        $table->addRow(250, ['height' => 250]);
        $this->filiereCell($table->addCell($leftW, ['vAlign' => 'center']), $layout, 'Section', $header['section'] ?? '');
        $this->filiereCell($table->addCell($rightW, ['vAlign' => 'center']), $layout, 'Année de formation', $header['annee_formation'] ?? '');
    }

    private function filiereCell($cell, array $layout, string $label, string $value): void
    {
        $run = $cell->addTextRun($this->para());
        $hasLabel = $label !== '';
        $hasValue = $value !== '';
        if ($hasLabel) {
            $run->addText($label . ' : ', ['bold' => true, 'size' => $this->sz(9, $layout)]);
        }
        if ($hasValue) {
            $run->addText($value, ['size' => $this->sz(9, $layout)]);
        }
    }

    /**
     * The grade grid: the name / average / observation header cells, the unit
     * headers, the coefficient row, one row per student and (for the plain
     * tables) the class average row. The exact shape comes from gradeGeometry():
     * the Stage sheet prints a full-width title banner and has no average
     * column, the synthesis sheet has no title row, and neither prints a
     * class-average row, exactly like the reference.
     */
    private function addGradeTable($section, array $data, array $layout): void
    {
        $subjects = $data['subjects'] ?? [];
        $students = $data['students'] ?? [];
        $avg = $data['class_average'] ?? null;
        $type = $data['table_type'] ?? 'mpcc1';
        $n = count($subjects);
        $studentH = (int) $layout['student_h'];

        $geo = $this->gradeGeometry($data);
        $nameW = $geo['name_w'];
        $subjW = $geo['subj_w'];
        $avgW = $geo['avg_w'];
        $obsW = $geo['obs_w'];
        $hasTitle = $geo['has_title'];
        $banner = $geo['banner'];
        $hasAverage = $geo['has_average'];
        $hasClassAverage = $geo['has_class_average'];

        // The name / average / observation labels start their vertical merge in
        // the first header row that carries them: the title row for a plain
        // sheet, the unit row for the Stage banner and the synthesis sheet.
        $mergeStart = $hasTitle && !$banner
            ? ['vMerge' => 'continue']
            : ['vMerge' => 'restart'];
        $startsMerge = $mergeStart['vMerge'] === 'restart';

        $isExam = str_starts_with($type, 'mpefcf');
        $gray = ['shading' => ['fill' => self::GRAY]];

        // Reference point sizes (page 1 of "1 récap des notes IP 3 eme 2024 2025"):
        // grid headers / coefficients 8, title 9, student name 6, grades 7 and
        // the bold average 6.
        // Reference point sizes (page 1 of "1 récap des notes IP 3 eme 2024 2025"):
        // header labels 8pt on a 5-unit sheet but 6pt on a 10-unit one, so the
        // header shrinks with the unit count; coefficients stay 8pt and the bold
        // averages 6pt on both. Titles/filière 9pt, student names 6pt, grades 7pt.
        $hdrFont = $this->sz($this->hdrPtFor($n), $layout);
        $titleFont = $this->sz(9, $layout);
        $nameFont = $this->sz(6, $layout);
        $studentFont = $this->sz(7, $layout);
        $avgFont = $this->sz(6, $layout);
        $coefFont = $this->sz(8, $layout);
        $avgRowFont = $this->sz(7, $layout);

        $table = $section->addTable($this->tableStyle(108));

        // Title row. A plain sheet keeps the name, title over the units, average
        // and observation on one row; the Stage sheet prints its title as a
        // full-width banner and the synthesis sheet has no title row at all.
        if ($hasTitle && $banner) {
            $table->addRow(260, ['height' => 260]);
            $this->gradeCell(
                $table->addCell($this::TABLE_W, ['gridSpan' => $n + 2] + $gray + ['vAlign' => 'center']),
                $this->tableTitleFor($type),
                ['bold' => true, 'size' => $titleFont, 'underline' => 'single']
            );
        } elseif ($hasTitle) {
            $table->addRow(260, ['height' => 260]);
            $this->gradeCell(
                $table->addCell($nameW, ['vMerge' => 'restart'] + $gray + ['vAlign' => 'center']),
                'Nom et Prénom',
                ['bold' => true, 'size' => $hdrFont]
            );
            $this->gradeCell(
                $table->addCell($subjW * $n, ['gridSpan' => $n] + $gray + ['vAlign' => 'center']),
                $this->tableTitleFor($type),
                ['bold' => true, 'size' => $titleFont, 'underline' => 'single']
            );
            $this->gradeCell(
                $table->addCell($avgW, ['vMerge' => 'restart'] + $gray + ['vAlign' => 'center']),
                $this->avgLabelFor($type),
                ['bold' => true, 'size' => $hdrFont]
            );
            $this->gradeCell(
                $table->addCell($obsW, ['vMerge' => 'restart'] + $gray + ['vAlign' => 'center']),
                $this->obsLabelFor($type),
                ['bold' => true, 'size' => $hdrFont]
            );
        }

        // Unit names row: the reference shades the unit labels but not the
        // vMerged name / observation continuations. When the sheet has no title
        // row (the Stage banner and the synthesis) this row carries the name /
        // average / observation labels and starts their vertical merge.
        $table->addRow(200, ['height' => 200]);
        $this->gradeCell(
            $table->addCell($nameW, $startsMerge ? $mergeStart + $gray + ['vAlign' => 'center'] : $mergeStart),
            $startsMerge ? 'Nom et Prénom' : '',
            $startsMerge ? ['bold' => true, 'size' => $hdrFont] : []
        );
        foreach ($subjects as $subject) {
            $this->gradeCell($table->addCell($subjW, $gray + ['vAlign' => 'center']), $subject['nom'], [
                'bold' => true,
                'size' => $hdrFont,
            ]);
        }
        if ($hasAverage) {
            $this->gradeCell(
                $table->addCell($avgW, $startsMerge ? $mergeStart + $gray + ['vAlign' => 'center'] : $mergeStart + $gray),
                $startsMerge ? $this->avgLabelFor($type) : '',
                $startsMerge ? ['bold' => true, 'size' => $hdrFont] : []
            );
        }
        $this->gradeCell(
            $table->addCell($obsW, $startsMerge ? $mergeStart + $gray + ['vAlign' => 'center'] : $mergeStart),
            $startsMerge ? $this->obsLabelFor($type) : '',
            $startsMerge ? ['bold' => true, 'size' => $hdrFont] : []
        );

        // Coefficient row: unshaded in the reference, so only the average column
        // keeps its grey stripe down the sheet.
        $table->addRow(200, ['height' => 200]);
        $this->gradeCell($table->addCell($nameW, ['vMerge' => 'continue']), '', []);
        foreach ($subjects as $subject) {
            $this->gradeCell($table->addCell($subjW, ['vAlign' => 'center']), $this->coefficientLabel($subject['coefficient']), [
                'bold' => true,
                'size' => $coefFont,
            ]);
        }
        if ($hasAverage) {
            $this->gradeCell($table->addCell($avgW, ['vMerge' => 'continue'] + $gray), '', []);
        }
        $this->gradeCell($table->addCell($obsW, ['vMerge' => 'continue']), '', []);

        // One row per student (unshaded), each an equal slice of the spare height.
        foreach ($students as $student) {
            $table->addRow($studentH, ['height' => $studentH]);
            $this->gradeCell($table->addCell($nameW, ['vAlign' => 'center']), $student['nom_prenom'], [
                'size' => $nameFont,
                'alignment' => Jc::LEFT,
            ]);
            foreach ($subjects as $subject) {
                $value = $student['notes'][$subject['id']] ?? null;
                $this->gradeCell(
                    $table->addCell($subjW, ['vAlign' => 'center']),
                    $this->formatValue($value, $isExam),
                    ['size' => $studentFont]
                );
            }
            if ($hasAverage) {
                $this->gradeCell(
                    $table->addCell($avgW, $gray + ['vAlign' => 'center']),
                    $this->formatValue($student['average'] ?? null, false),
                    ['bold' => true, 'size' => $avgFont]
                );
            }
            $this->gradeCell($table->addCell($obsW, ['vAlign' => 'center']), '', []);
        }

        // Class average row. The Stage and synthesis sheets have none, like the
        // reference, so the row is skipped when the geometry drops it.
        if ($avg && $hasClassAverage) {
            $table->addRow(260, ['height' => 260]);
            $this->gradeCell(
                $table->addCell($nameW, $gray + ['vAlign' => 'center']),
                'Moyenne de la classe',
                ['bold' => true, 'size' => $avgRowFont]
            );
            foreach ($subjects as $subject) {
                $this->gradeCell(
                    $table->addCell($subjW, $gray + ['vAlign' => 'center']),
                    $this->formatValue($avg['subjects'][$subject['id']] ?? null, $isExam),
                    ['bold' => true, 'size' => $avgRowFont]
                );
            }
            if ($hasAverage) {
                $this->gradeCell(
                    $table->addCell($avgW, $gray + ['vAlign' => 'center']),
                    $this->formatValue($avg['overall'] ?? null, false),
                    ['bold' => true, 'size' => $avgRowFont]
                );
            }
            $this->gradeCell($table->addCell($obsW, ['vAlign' => 'center']), '', []);
        }
    }

    private function gradeCell($cell, string $text, array $font): void
    {
        $paragraph = $cell->addTextRun([
            ...$this->para(),
            'alignment' => $font['alignment'] ?? Jc::CENTER,
        ]);
        $paragraph->addText($text !== '' ? $text : ' ', [
            'bold' => $font['bold'] ?? false,
            'size' => $font['size'] ?? 6,
            'underline' => $font['underline'] ?? 'none',
            'name' => self::FONT,
        ]);
    }

    /**
     * Signature block: 4 columns matching the reference sheet's own grid, with a
     * centred bold label per column and an empty high row for the signatures.
     */
    private function addSignatureTable($section, array $layout, array $subjects): void
    {
        $widths = self::SIG_W;
        $labels = [
            'Signature de président de jury',
            'Signature des membres de jury externe',
            'Signature de membre de jury interne',
            'Signature du représentant de la formation professionnelle',
        ];

        $table = $section->addTable($this->tableStyle(108));

        $table->addRow(280, ['height' => 280]);
        foreach ($labels as $i => $label) {
            $table->addCell($widths[$i], ['vAlign' => 'center'])->addText($label, [
                'bold' => true,
                'size' => $this->sz(7, $layout),
            ], $this->para(['alignment' => Jc::CENTER]));
        }

        // Empty signature strip: exact so Word never grows the last table row
        // beyond its height (an atLeast row here rendered ~37pt tall and pushed
        // the trailing paragraph to a blank page 2).
        $table->addRow((int) $layout['signature_blank'], [
            'height' => (int) $layout['signature_blank'],
            'exactHeight' => true,
        ]);
        foreach ($widths as $w) {
            $table->addCell($w)->addText(' ', ['size' => $this->sz(8, $layout)], $this->para());
        }
    }

    /**
     * Compact paragraph defaults: without explicit nowrap Word's Normal style
     * adds 8pt space-after to every paragraph, which inflated every table row
     * (a 6pt font row rendered ~22pt tall and pushed the sheet to 2 pages).
     */
    private function para(array $extra = []): array
    {
        return $extra + ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1];
    }

    /**
     * Base table style: full width, thin black borders and no vertical cell
     * padding (vertical padding is what inflated the student rows before).
     * Left alignment + explicit cell widths makes Word place every table at the
     * exact same left margin, so nothing drifts relative to the grade grid.
     */
    private function tableStyle(int $sideMargin): array
    {
        return [
            'width' => self::TABLE_W,
            'layout' => TableStyle::LAYOUT_FIXED,
            'alignment' => Jc::LEFT,
            'borderSize' => 6,
            'borderColor' => '000000',
            'cellMarginLeft' => $sideMargin,
            'cellMarginRight' => $sideMargin,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
        ];
    }

    public function formatValue(?float $value, bool $trimDecimals): string
    {
        if ($value === null) {
            return '';
        }
        $formatted = number_format((float) $value, 2, ',', '');
        if ($trimDecimals) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }
        return $formatted;
    }

    /**
     * The coefficient printed above a subject: a plain integer when the weight is
     * exact (30 / 20 / 30 / 20) and a two-decimal French value otherwise
     * (36,67 / 26,66 / 36,67), so the row matches the bulletin's percentages.
     *
     * @param int|float $coefficient
     */
    public function coefficientLabel($coefficient): string
    {
        $value = (float) $coefficient;

        return abs($value - round($value)) < 0.005
            ? (string) (int) round($value)
            : number_format($value, 2, ',', '');
    }

    /**
     * Short code of the chosen table (used on its own line and in the filename):
     * MPCC1 / MPCC GLOBAL / MPEFCFT2 / MPEP-style labels as in the reference.
     */
    public function tableLabelFor(string $type): string
    {
        return match ($type) {
            'mpcc1' => 'MPCC1',
            'mpcc2' => 'MPCC2',
            'mpcc_global' => 'MPCC GLOBAL',
            'mpefcf1' => 'MPEFCFT1',
            'mpefcf2' => 'MPEFCFT2',
            'mpefcf_global' => 'MPEFCFT GLOBAL',
            'mpefcfp1' => 'MPEFCFP1',
            'mpefcfp2' => 'MPEFCFP2',
            'mpefcfp_global' => 'MPEFCFP GLOBAL',
            'nsti' => 'NSTI',
            'stage' => 'STAGE',
            'synthese' => 'SYNTHÈSE',
            default => 'RELEVE',
        };
    }

    /**
     * The sheet is named after its group ("Groupe A"), which is what tells two
     * groups of the same filière / year apart. A header without a group (the
     * reference export) falls back to the filière section.
     */
    private function sheetNameFor(array $header): string
    {
        $group = trim((string) ($header['groupe'] ?? ''));

        return $group !== '' ? $group : trim((string) ($header['section'] ?? ''));
    }

    /**
     * First line of the document, naming the filière, the group and the table
     * being printed.
     */
    public function pageTitleFor(string $type, array $header): string
    {
        $part = static fn ($v) => trim((string) ($v ?? ''));
        $bits = array_filter([
            'RELEVÉ DES NOTES',
            $part($header['filiere'] ?? null),
            $part($this->sheetNameFor($header)),
            $part($header['annee_scolaire'] ?? null),
            $this->tableLabelFor($type),
        ], fn ($b) => $b !== '');

        return implode(' — ', $bits);
    }

    /**
     * Download name matches the page title so the sheet is easy to recognise
     * after saving, e.g. "RELEVÉ DES NOTES — Infirmier Polyvalent — Groupe A —
     * 2025/2026 — MPCC1.docx". The school year slash is the only character
     * Windows forbids in a file name, so it becomes a hyphen.
     */
    public function buildFilename(array $data): string
    {
        $title = $this->pageTitleFor($data['table_type'] ?? 'mpcc1', $data['header'] ?? []);
        $title = preg_replace('~[/\\\\:*?"<>|]+~', '-', $title);

        return rtrim($title, ' .') . '.docx';
    }

    /**
     * Download name of the combined document ("… — Tous les tableaux.docx"),
     * sharing the filière / section / year part of the single-table name so the
     * two files sort together.
     */
    public function buildAllFilename(array $data): string
    {
        $part = static fn ($v) => trim((string) ($v ?? ''));
        $bits = array_filter([
            'RELEVÉ DES NOTES',
            $part($data['header']['filiere'] ?? null),
            $part($this->sheetNameFor($data['header'] ?? [])),
            $part($data['header']['annee_scolaire'] ?? null),
            'Tous les tableaux',
        ], fn ($b) => $b !== '');

        $title = preg_replace('~[/\\\\:*?"<>|]+~', '-', implode(' — ', $bits));

        return rtrim($title, ' .') . '.docx';
    }

    /**
     * Titles taken verbatim from the reference recap document, e.g. the MPCC1
     * page reads "Tableau récapitulatif des résultats des contrôles continus
     * (BLOC1)" and the global pages "de fin cursus de formation".
     */
    public function tableTitleFor(string $type): string
    {
        return match ($type) {
            'mpcc1' => 'Tableau récapitulatif des résultats des contrôles continus (BLOC1)',
            'mpcc2' => 'Tableau récapitulatif des résultats des contrôles continus (BLOC2)',
            'mpcc_global' => 'Tableau récapitulatif des résultats des contrôles continus de fin cursus de formation',
            'mpefcf1' => 'Tableau récapitulatif du résultat des examens Théorique (BLOC1)',
            'mpefcf2' => 'Tableau récapitulatif du résultat des examens Théorique (BLOC2)',
            'mpefcf_global' => 'Examens Théorique de fin de cursus de formation (durant tout le cursus de formation)',
            'mpefcfp1' => 'Tableau récapitulatif du résultat des examens Pratique (BLOC1)',
            'mpefcfp2' => 'Tableau récapitulatif du résultat des examens Pratique (BLOC2)',
            'mpefcfp_global' => 'Examens Pratique de fin de cursus de formation (durant tout le cursus de formation)',
            'nsti' => "Critères arrêtés par le jury d'examens",
            'stage' => 'Tableau récapitulatif des résultats de stage (durant tout le cursus de formation)',
            'synthese' => '',
            default => 'Tableau récapitulatif des résultats',
        };
    }

    /**
     * Average-column header: MPCC1*, MPCC2*, MPCC* for the global sheet,
     * MPEFCFT1/MPEFCFT2/MPET for theoretical, MPEFCFP1/MPEFCFP2/MPEP for
     * practical, each suffixed with a star as in the reference document.
     */
    public function avgLabelFor(string $type): string
    {
        return match ($type) {
            'mpcc1' => 'MPCC1*',
            'mpcc2' => 'MPCC2*',
            'mpcc_global' => 'MPCC*',
            'mpefcf1' => 'MPEFCFT1*',
            'mpefcf2' => 'MPEFCFT2*',
            'mpefcf_global' => 'MPET*',
            'mpefcfp1' => 'MPEFCFP1*',
            'mpefcfp2' => 'MPEFCFP2*',
            'mpefcfp_global' => 'MPEP*',
            'nsti' => 'NSTI*',
            // The Stage sheet has no average column; the synthesis average is the
            // final mark NF, starred like the reference.
            'stage' => '',
            'synthese' => 'NF*****',
            default => 'MPCC*',
        };
    }

    /**
     * Header of the last (observation) column: the NSTI jury sheet calls it
     * "Proposition/ commentaire de jury d'examen" in the reference, and the Stage
     * and synthesis sheets reuse the same wording.
     */
    public function obsLabelFor(string $type): string
    {
        return in_array($type, ['nsti', 'stage', 'synthese'], true)
            ? "Proposition/ commentaire de jury d'examen"
            : 'Observation des formateurs';
    }
}
