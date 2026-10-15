<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Builds the "bulletin de notes" as a .docx, one A4-portrait page per student,
 * reproducing the reference document
 * ("Bulletins_IP3_2025-2026_WORD_SANS_LIGNES_VIDES.docx"):
 *
 *  - the page title ("Bulletin de fin de formation" for the final year, the
 *    "passage" wording otherwise),
 *  - the borderless identity block (label / aligned colon / value),
 *  - the units grid (unité / coefficient / notes CC / théorique / pratique),
 *  - the bottom band: "Moyenne de note obtenue" on the left and "Moyenne
 *    Générale /20" + "Décision du jury" on the right,
 *  - the "Cachet et signature de la directrice" line.
 *
 * The page header carries the institute banner and the authorization line, the
 * footer the address / contacts / website, exactly like the relevé des notes.
 * The layout is plain flowing tables (no floating boxes) so Word reflows it
 * naturally while printing.
 */
class BulletinDocxService
{
    private const FONT = 'Century Gothic';
    private const FOOTER_FONT = 'Times New Roman';

    // A4 portrait usable width (page 11906 − margins 696 × 2).
    private const TABLE_W = 10508;

    // Units grid transcribed from the reference
    // [5848, 1313, 1130, 1170, 1096] scaled to TABLE_W.
    private const UNIT_COLS = [5821, 1307, 1125, 1165, 1090];

    // Identity grid: label / colon / value. The reference keeps every ":" on the
    // same vertical (x182pt) and starts every value at x203pt.
    private const ID_COLS = [2900, 400, 6218];

    // Bottom band: the left summary grid and the right moyenne grid, separated
    // by the reference's 996-twip gap (reference widths 5530 and 3638).
    private const BAND_LEFT_W = 6546;
    private const BAND_RIGHT_W = 3962;
    private const SUMMARY_NAME_W = 4101;
    private const SUMMARY_VAL_W = 1429;
    private const MOYENNE_NAME_W = 1832;
    private const MOYENNE_VAL_W = 1806;

    private const LOGO_W = 263;
    private const LOGO_H = 29;

    /** Page geometry, from the reference sectPr (pgSz 11900 × 16820). */
    private const MARGIN_TW = 1440;
    private const HEADER_TW = 426;
    private const FOOTER_TW = 350;

    /** Row heights (twips), from the reference trHeight values. */
    private const ID_ROW_H = 297;

    // ---------------------------------------------------------------------
    // Public API
    // ---------------------------------------------------------------------

    public function build(array $bulletin, array $context): string
    {
        return $this->save($this->buildPhpWord($bulletin, $context));
    }

    /**
     * Every student of a group in a single .docx: the first bulletin opens the
     * document, each following one starts on its own page.
     */
    public function buildAll(array $bulletins, array $context): string
    {
        return $this->save($this->buildAllPhpWord($bulletins, $context));
    }

    public function buildPhpWord(array $bulletin, array $context): PhpWord
    {
        $phpWord = $this->newPhpWord();
        $this->addBulletin($phpWord, $bulletin, $context, false);

        return $phpWord;
    }

    public function buildAllPhpWord(array $bulletins, array $context): PhpWord
    {
        $phpWord = $this->newPhpWord();
        $first = true;
        foreach ($bulletins as $bulletin) {
            $this->addBulletin($phpWord, $bulletin, $context, !$first);
            $first = false;
        }

        return $phpWord;
    }

    // ---------------------------------------------------------------------
    // Document / page
    // ---------------------------------------------------------------------

    private function save(PhpWord $phpWord): string
    {
        if (!class_exists('ZipArchive')) {
            \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'bulletin_');
        $writer->save($tmp);

        return $tmp;
    }

    private function newPhpWord(): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName(self::FONT);
        // Paragraph marks default to a small size so an empty paragraph never
        // inflates a block; every run passes its own explicit size.
        $phpWord->setDefaultFontSize(7);

        return $phpWord;
    }

    private function addBulletin(PhpWord $phpWord, array $bulletin, array $context, bool $newPage): void
    {
        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => Converter::cmToTwip(21.0),
            'pageSizeH' => Converter::cmToTwip(29.7),
            'marginTop' => self::MARGIN_TW,
            'marginBottom' => self::MARGIN_TW,
            'marginLeft' => 696,
            'marginRight' => 696,
            'headerHeight' => self::HEADER_TW,
            'footerHeight' => self::FOOTER_TW,
        ]);

        $this->addHeader($section);
        $this->addFooter($section);

        $this->addTitle($section, $context, $newPage);
        $this->addSpacer($section, 28);
        $this->addIdentityTable($section, $bulletin, $context);
        $this->addSpacer($section, 599);
        $this->addUnitsTable($section, $bulletin);
        $this->addSpacer($section, 382);
        $this->addBand($section, $bulletin);
        $this->addSpacer($section, 338);
        $this->addCachet($section);
    }

    // ---------------------------------------------------------------------
    // Header / footer
    // ---------------------------------------------------------------------

    private function addHeader($section): void
    {
        $header = $section->addHeader();
        $logo = public_path('images/institut_logo.png');

        if (is_file($logo)) {
            // Inline image: it keeps its own line's height and pushes the
            // authorization line below it instead of printing over it.
            $header->addTextRun($this->para())->addImage($logo, [
                'width' => self::LOGO_W,
                'height' => self::LOGO_H,
            ]);
        }

        $header->addText(
            "Etablissement privé autorisé par L'État le : 12/01/2022 sous le N° :6/05/1/2022",
            ['bold' => true, 'size' => 8, 'name' => self::FONT],
            [...$this->para(), 'alignment' => Jc::LEFT]
        );
    }

    private function addFooter($section): void
    {
        $footer = $section->addFooter();

        $run = $footer->addTextRun([...$this->para(), 'alignment' => Jc::CENTER]);
        $run->addText(
            'Adresse : Immeuble N° :25 Avenue Al Bakay Lhbil Berkane, Tel Fixe : 05 36 61 10 10, '
            . 'Tel Mobile : 06 61 61 27 67, Adresse Mail : ifpberkane@gmail.com,Site Web : ',
            ['size' => 6.5, 'name' => self::FOOTER_FONT]
        );
        $run->addText('www.institutifp.com', [
            'size' => 6.5,
            'name' => self::FOOTER_FONT,
            'color' => '0070C0',
        ]);
    }

    // ---------------------------------------------------------------------
    // Blocks
    // ---------------------------------------------------------------------

    private function addTitle($section, array $context, bool $newPage): void
    {
        $style = [...$this->para(), 'alignment' => Jc::CENTER];
        if ($newPage) {
            $style['pageBreakBefore'] = true;
        }

        $section->addText(
            (string) ($context['title'] ?? 'Bulletin de notes'),
            ['bold' => true, 'size' => 22, 'name' => self::FONT],
            $style
        );
    }

    /**
     * Identity block: six borderless rows, "label : value" with the label and
     * the ":" bold and every colon / value kept on the same vertical.
     */
    private function addIdentityTable($section, array $bulletin, array $context): void
    {
        $rows = [
            ['Nom et Prénom', $bulletin['nom_prenom'] ?? ''],
            ['N° inscription', $bulletin['numero_inscription'] ?? ''],
            ['Filière', $context['filiere'] ?? ''],
            ['Section', $context['section'] ?? ''],
            ['Année scolaire', $context['annee_scolaire'] ?? ''],
            ['Année de formation', $context['annee_formation'] ?? ''],
        ];

        $table = $section->addTable($this->borderlessTableStyle(self::ID_COLS[0] + self::ID_COLS[1] + self::ID_COLS[2], 110));
        $font = ['size' => 11, 'name' => self::FONT];

        foreach ($rows as [$label, $value]) {
            $table->addRow(self::ID_ROW_H, ['height' => self::ID_ROW_H]);

            $table->addCell(self::ID_COLS[0], ['vAlign' => 'center'])->addText(
                $label,
                $font + ['bold' => true],
                $this->para()
            );

            $table->addCell(self::ID_COLS[1], ['vAlign' => 'center'])->addText(
                ':',
                $font + ['bold' => true],
                $this->para()
            );

            $table->addCell(self::ID_COLS[2], ['vAlign' => 'center'])->addText(
                (string) $value,
                $font,
                $this->para()
            );
        }
    }

    /**
     * Units grid: the reference header (10pt bold, centred) then one row per
     * unit with its coefficient (bold) and its three notes (trailing zeros
     * trimmed, blank values printed as "-").
     */
    private function addUnitsTable($section, array $bulletin): void
    {
        $table = $section->addTable($this->tableStyle(60));
        $headerFont = ['bold' => true, 'size' => 10, 'name' => self::FONT];
        $nameFont = ['size' => 9, 'name' => self::FONT];
        $coefFont = ['bold' => true, 'size' => 10, 'name' => self::FONT];
        $noteFont = ['size' => 10, 'name' => self::FONT];

        $table->addRow();
        $headers = ['Unité', 'Coefficient', 'Notes CC', 'Note examen théorique', 'Note examen pratique'];
        foreach (self::UNIT_COLS as $i => $w) {
            $this->cell($table->addCell($w, ['vAlign' => 'center']), $headers[$i], $headerFont, Jc::CENTER);
        }

        foreach ($bulletin['unites'] ?? [] as $unite) {
            $table->addRow();
            $this->cell($table->addCell(self::UNIT_COLS[0], ['vAlign' => 'center']), (string) $unite['nom'], $nameFont, Jc::LEFT);
            $this->cell($table->addCell(self::UNIT_COLS[1], ['vAlign' => 'center']), $this->intLabel($unite['coefficient'] ?? null), $coefFont, Jc::CENTER);
            $this->cell($table->addCell(self::UNIT_COLS[2], ['vAlign' => 'center']), $this->cellValue($unite['moyenne_cc'] ?? null), $noteFont, Jc::CENTER);
            $this->cell($table->addCell(self::UNIT_COLS[3], ['vAlign' => 'center']), $this->cellValue($unite['moyenne_theorique'] ?? null), $noteFont, Jc::CENTER);
            $this->cell($table->addCell(self::UNIT_COLS[4], ['vAlign' => 'center']), $this->cellValue($unite['moyenne_pratique'] ?? null), $noteFont, Jc::CENTER);
        }
    }

    /**
     * Bottom band: the left "Moyenne de note obtenue" grid (one line per
     * component, weight in brackets, 2-decimal value) and, to its right, the
     * "Moyenne Générale /20" and "Décision du jury" grid.
     */
    private function addBand($section, array $bulletin): void
    {
        $table = $section->addTable($this->borderlessTableStyle(self::BAND_LEFT_W + self::BAND_RIGHT_W, 0));
        $table->addRow(null);
        $left = $table->addCell(self::BAND_LEFT_W, ['vAlign' => 'top']);
        $right = $table->addCell(self::BAND_RIGHT_W, ['vAlign' => 'top']);

        $this->addSummaryTable($left, $bulletin);
        $this->addMoyenneTable($right, $bulletin);
    }

    private function addSummaryTable($cell, array $bulletin): void
    {
        $table = $cell->addTable($this->bandTableStyle(self::SUMMARY_NAME_W + self::SUMMARY_VAL_W));

        $table->addRow(380, ['height' => 380]);
        $this->cell(
            $table->addCell(self::SUMMARY_NAME_W + self::SUMMARY_VAL_W, ['gridSpan' => 2, 'vAlign' => 'center']),
            'Moyenne de note obtenue',
            ['bold' => true, 'size' => 11, 'name' => self::FONT],
            Jc::CENTER
        );

        foreach ($this->components($bulletin) as $component) {
            $table->addRow();
            $this->cell(
                $table->addCell(self::SUMMARY_NAME_W, ['vAlign' => 'center']),
                $component['label'],
                ['size' => 9, 'name' => self::FONT],
                Jc::LEFT
            );
            $this->cell(
                $table->addCell(self::SUMMARY_VAL_W, ['vAlign' => 'center']),
                $this->numberLabel($component['value']),
                ['bold' => true, 'size' => 11, 'name' => self::FONT],
                Jc::CENTER
            );
        }
    }

    private function addMoyenneTable($cell, array $bulletin): void
    {
        // The reference starts this block a little below the left one.
        $cell->addText(' ', ['size' => 2, 'name' => self::FONT], [
            ...$this->para(),
            'spaceBefore' => 482,
        ]);

        $table = $cell->addTable($this->bandTableStyle(self::MOYENNE_NAME_W + self::MOYENNE_VAL_W));

        $table->addRow(541, ['height' => 541]);
        $this->cell(
            $table->addCell(self::MOYENNE_NAME_W, ['vAlign' => 'center']),
            'Moyenne Générale /20',
            ['size' => 10, 'name' => self::FONT],
            Jc::LEFT
        );
        $this->cell(
            $table->addCell(self::MOYENNE_VAL_W, ['vAlign' => 'center']),
            $this->numberLabel($bulletin['moyenne_generale'] ?? null),
            ['bold' => true, 'size' => 11, 'name' => self::FONT],
            Jc::CENTER
        );

        $table->addRow(541, ['height' => 541]);
        $this->cell(
            $table->addCell(self::MOYENNE_NAME_W, ['vAlign' => 'center']),
            'Décision du jury',
            ['size' => 10, 'name' => self::FONT],
            Jc::LEFT
        );
        $this->cell(
            $table->addCell(self::MOYENNE_VAL_W, ['vAlign' => 'center']),
            (string) ($bulletin['decision'] ?? ''),
            ['bold' => true, 'size' => 11, 'name' => self::FONT],
            Jc::CENTER
        );
    }

    private function addCachet($section): void
    {
        $section->addText(
            'Cachet et signature de la directrice',
            ['bold' => true, 'size' => 14, 'name' => self::FONT],
            [...$this->para(), 'alignment' => Jc::RIGHT, 'indentation' => ['right' => 550]]
        );
    }

    // ---------------------------------------------------------------------
    // Data helpers
    // ---------------------------------------------------------------------

    /**
     * Components of the final mark in reference order: contrôle continu,
     * théorique, pratique and, when the programme ends with a soutenance, the
     * NSTI line. A missing mark is printed as "-" so the band always lists the
     * same rows.
     *
     * @return array<int,array{label:string,value:?float}>
     */
    private function components(array $bulletin): array
    {
        $weights = $bulletin['weights'] ?? [];
        $components = [
            ['key' => 'cc', 'label' => 'Contrôle continu', 'value' => $bulletin['moyenne_cc'] ?? null],
            ['key' => 'theorique', 'label' => 'Examen théorique', 'value' => $bulletin['moyenne_theorique'] ?? null],
            ['key' => 'pratique', 'label' => 'Examen pratique', 'value' => $bulletin['moyenne_pratique'] ?? null],
        ];

        if ($bulletin['has_soutenance'] ?? false) {
            $components[] = [
                'key' => 'nsti',
                'label' => 'travaux individuels de fin de formation',
                'value' => $bulletin['nsti'] ?? null,
            ];
        }

        return array_map(function (array $c) use ($weights) {
            $weight = $weights[$c['key']] ?? null;

            return [
                'label' => $weight === null
                    ? $c['label']
                    : $c['label'] . ' (' . $this->percentLabel($weight) . '%)',
                'value' => $c['value'],
            ];
        }, $components);
    }

    /**
     * Weight x100 with French decimals: "30" when exact, "36,67" / "26,66"
     * otherwise, so the band matches the official 30/20/30/20 and the
     * historical 36,67/26,66/36,67 splits.
     */
    public function percentLabel(float $weight): string
    {
        $value = round($weight * 100, 2);

        return abs($value - round($value)) < 0.005
            ? (string) (int) round($value)
            : number_format($value, 2, ',', '');
    }

    /**
     * Unit-table cell: two-decimal French value with trailing zeros trimmed
     * ("19", "14,5", "14,58"), "-" when the mark is missing.
     */
    public function cellValue(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /** Band cell: always two decimals ("16,12", "14,00"), "-" when missing. */
    public function numberLabel(?float $value): string
    {
        return $value === null ? '-' : number_format($value, 2, ',', '');
    }

    /** Coefficient of a unit, printed as a plain integer. */
    private function intLabel($value): string
    {
        if ($value === null) {
            return '-';
        }

        return (string) (int) round((float) $value);
    }

    /**
     * Title of the bulletin: the final year closes the cursus, every earlier
     * year is a passage bulletin.
     */
    public function titleFor(int $numero, int $nombreAnnees): string
    {
        if ($nombreAnnees <= 0 || $numero >= $nombreAnnees) {
            return 'Bulletin de fin de formation';
        }

        $ordinal = [1 => 'première', 2 => 'deuxième', 3 => 'troisième', 4 => 'quatrième'];

        return sprintf(
            "Bulletins de passage de la %s année à la %s année",
            $ordinal[$numero] ?? ($numero . 'ème'),
            $ordinal[$numero + 1] ?? (($numero + 1) . 'ème')
        );
    }

    /**
     * Download name of a single bulletin, e.g.
     * "BULLETIN — Infirmier Polyvalent — Technocien Specialise — 2025/2026 —
     * HAJI Nabil.docx". The school-year slash is the only character Windows
     * forbids in a file name, so it becomes a hyphen.
     */
    public function buildFilename(array $bulletin, array $context): string
    {
        return $this->joinName('BULLETIN', [
            $context['filiere'] ?? '',
            $context['section'] ?? '',
            $context['annee_scolaire'] ?? '',
            $bulletin['nom_prenom'] ?? '',
        ]);
    }

    /** Download name of the whole-group document ("BULLETINS — …"). */
    public function buildAllFilename(array $context): string
    {
        return $this->joinName('BULLETINS', [
            $context['filiere'] ?? '',
            $context['section'] ?? '',
            $context['annee_scolaire'] ?? '',
        ]);
    }

    private function joinName(string $prefix, array $parts): string
    {
        $bits = array_filter(
            array_map(static fn ($p) => trim((string) $p), array_merge([$prefix], $parts)),
            static fn ($b) => $b !== ''
        );

        $title = preg_replace('~[/\\\\:*?"<>|]+~', '-', implode(' — ', $bits));

        return rtrim($title, ' .') . '.docx';
    }

    // ---------------------------------------------------------------------
    // Low-level helpers
    // ---------------------------------------------------------------------

    private function cell($cell, string $text, array $font, string $alignment): void
    {
        $cell->addText($text, $font, [...$this->para(), 'alignment' => $alignment]);
    }

    private function addSpacer($section, int $twips): void
    {
        $section->addText(' ', ['size' => 2, 'name' => self::FONT], [
            ...$this->para(),
            'spaceBefore' => $twips,
        ]);
    }

    private function para(array $extra = []): array
    {
        return $extra + ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1];
    }

    /**
     * Table style of the units / band grids: fixed width, thin black borders and
     * no vertical cell padding so rows stay tight.
     */
    private function tableStyle(int $sideMargin): array
    {
        return [
            'width' => self::TABLE_W,
            'layout' => TableStyle::LAYOUT_FIXED,
            'alignment' => Jc::LEFT,
            'borderSize' => 8,
            'borderColor' => '000000',
            'cellMarginLeft' => $sideMargin,
            'cellMarginRight' => $sideMargin,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
        ];
    }

    /**
     * Same fixed layout as the units table but without borders: the identity
     * block and the bottom-band container are invisible grids. No border key is
     * set at all, so PhpWord writes no `w:tblBorders` (a `borderSize` of 0 still
     * draws a hairline in Word).
     */
    private function borderlessTableStyle(int $width, int $sideMargin): array
    {
        return [
            'width' => $width,
            'layout' => TableStyle::LAYOUT_FIXED,
            'alignment' => Jc::LEFT,
            'cellMarginLeft' => $sideMargin,
            'cellMarginRight' => $sideMargin,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
        ];
    }

    /** Borderless nested table of the bottom band, at an explicit width. */
    private function bandTableStyle(int $width): array
    {
        return [
            'width' => $width,
            'layout' => TableStyle::LAYOUT_FIXED,
            'alignment' => Jc::LEFT,
            'borderSize' => 8,
            'borderColor' => '000000',
            'cellMarginLeft' => 60,
            'cellMarginRight' => 60,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
        ];
    }
}
