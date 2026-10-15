<?php

namespace App\Services;

use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Table as TableStyle;

/**
 * Builds the two editable .docx sheets of the admin "Étudiants" page.
 *
 *  - "Liste stagiaire" reproduces the reference file
 *    "word/Liste des stagiaires IP 2 2025-2026.docx": an identification block
 *    (Filière / Année Scolaire / Section / Année de formation / Promotion)
 *    followed by the Nom et Prénom / CIN / N° D'inscription list. The block is
 *    emitted per group, so its filière / section / promotion always describe the
 *    students printed under it, and every group starts on its own page.
 *  - "Identifiants et mots de passe" is the account list the admin used to give
 *    out (identifiant + mot de passe), with the N° d'inscription so each line can
 *    be matched back to a student.
 *
 * Both sheets are A4 portrait carrying the institute authorization line in the
 * page header and the institute address in the footer, and use plain in-flow
 * tables (no floating boxes) whose column header repeats, so a class that does
 * not fit one page simply continues on the next one. The reference sheet's grids
 * are wider than its own text area and bleed into the page margins, so the page
 * is set up with narrow side margins (usable width 10977 twips = the reference
 * block's width) and every grid keeps the reference's twip widths.
 */
class EtudiantDocxService
{
    private const FONT = 'Century Gothic';

    // A4 portrait, in twips.
    private const PAGE_W = 11906;
    private const PAGE_H = 16838;
    private const MARGIN_TOP = 851;
    private const MARGIN_BOTTOM = 851;
    private const MARGIN_LEFT = 465;
    private const MARGIN_RIGHT = 464;
    private const HEADER_H = 851;
    private const FOOTER_H = 340;

    // Usable width, taken from the reference identification block (5529 + 5448).
    private const TABLE_W = 10977;

    // Identification block grid and its reference row heights.
    private const ID_COLS = [5529, 5448];
    private const ID_ROW_TITLE = 114;
    private const ID_ROW_FILIERE = 243;
    private const ID_ROW_LINE = 77;

    // Student list grid (4509 / 2904 / 3509 twips), centred on the page, and the
    // identifiers grid: n°, name, N° d'inscription, identifiant, mot de passe.
    private const LIST_W = 10922;
    private const LIST_COLS = [4509, 2904, 3509];
    private const CRED_COLS = [600, 2900, 2400, 2500, 2577];

    // The reference indents its student names by 449 twips and hangs the list
    // number in front of them.
    private const LIST_NAME_INDENT = 449;

    // Small band between the identification block and the list, so the two grids
    // do not merge into a single table (the empty paragraph's own line plus this
    // space-after reproduces the reference's gap).
    private const BLOCK_GAP = 100;

    // The reference file opens every page with an empty paragraph before the
    // identification block, which is what keeps the block clear of the
    // authorization line.
    private const LEADING_SIZE = 12;

    // The reference authorization line sits 142 twips left of its own text area;
    // the wider text area used here needs this indent to land on the same spot.
    private const HEADER_TEXT_INDENT = 810;

    // Space above the authorization line, matching the reference header.
    private const HEADER_SPACE_BEFORE = 173;

    private const AUTH_TEXT = "Etablissement privé autorisé par L'État le : 12/01/2022 sous le N° :6/05/1/2022";

    private const FOOTER_TEXT = 'Adresse : Immeuble N° :25 Avenue Al Bakay Lhbil Berkane, Tel Fixe : 05 36 61 10 10, '
        . 'Tel Mobile : 06 61 61 27 67, Adresse Mail : ifpberkane@gmail.com,Site Web : ';

    private const SITE_URL = 'www.institutifp.com';

    /**
     * The "Liste stagiaire" sheet: one identification block and its student table
     * per group, each group on its own page.
     *
     * @param array<int, array<string, mixed>> $groups
     */
    public function buildListe(array $groups): string
    {
        $phpWord = $this->newPhpWord();

        foreach (array_values($groups) as $index => $group) {
            // One numbering definition per group, so every group's list restarts
            // at 1 whatever the previous page numbered.
            $numbering = 'ListeStagiaires' . $index;
            $this->addListNumbering($phpWord, $numbering);

            $section = $this->addSection($phpWord);
            $this->addLeadingGap($section);
            $this->addIdentificationBlock($section, $group);
            $this->addBlockGap($section);
            $this->addListTable($section, $group['etudiants'] ?? [], $numbering);
        }

        return $this->save($phpWord);
    }

    /**
     * The identifiers sheet: the filtered students' identifiant and mot de passe,
     * in the same order as the list page.
     *
     * @param array{header: array<string, string>, rows: array<int, array<string, string>>} $data
     */
    public function buildIdentifiants(array $data): string
    {
        $phpWord = $this->newPhpWord();
        $section = $this->addSection($phpWord);
        $this->addLeadingGap($section);

        $block = $section->addTable($this->tableStyle(self::TABLE_W, Jc::LEFT));

        $block->addRow(self::ID_ROW_TITLE, ['height' => self::ID_ROW_TITLE]);
        $block->addCell(self::TABLE_W, ['gridSpan' => 5, 'vAlign' => 'center'])->addText(
            'Identifiants et mots de passe',
            ['bold' => true, 'underline' => 'single', 'size' => 14],
            $this->para(['alignment' => Jc::CENTER, 'indentation' => ['left' => 164]])
        );

        $block->addRow(self::ID_ROW_FILIERE, ['height' => self::ID_ROW_FILIERE]);
        $block->addCell(self::TABLE_W, ['gridSpan' => 5, 'vAlign' => 'center'])->addText(
            $this->scopeLabel($data['header'] ?? []),
            ['size' => 12],
            $this->para(['alignment' => Jc::CENTER])
        );

        $this->addBlockGap($section);

        $table = $section->addTable($this->tableStyle(self::TABLE_W, Jc::LEFT));

        // Column headers repeat on every page of a long list.
        $table->addRow(null, ['tblHeader' => true]);
        $labels = ['#', 'Nom et Prénom', "N° D'inscription", 'Identifiant', 'Mot de passe'];
        foreach ($labels as $i => $label) {
            $table->addCell(self::CRED_COLS[$i], ['vAlign' => 'center'])->addText(
                $label,
                ['bold' => true, 'size' => 12],
                $this->para(['alignment' => Jc::CENTER])
            );
        }

        $number = 0;
        foreach ($data['rows'] ?? [] as $row) {
            $number++;
            $table->addRow();
            $table->addCell(self::CRED_COLS[0], ['vAlign' => 'center'])->addText(
                (string) $number,
                ['size' => 11],
                $this->para(['alignment' => Jc::CENTER])
            );
            $table->addCell(self::CRED_COLS[1], ['vAlign' => 'center'])->addText(
                $this->value($row['nom_prenom'] ?? null),
                ['size' => 11],
                $this->para(['indentation' => ['left' => 449]])
            );
            $table->addCell(self::CRED_COLS[2], ['vAlign' => 'center'])->addText(
                $this->value($row['numero_inscription'] ?? null),
                ['size' => 11],
                $this->para(['alignment' => Jc::CENTER])
            );
            $table->addCell(self::CRED_COLS[3], ['vAlign' => 'center'])->addText(
                $this->value($row['identifiant'] ?? null),
                ['size' => 11],
                $this->para(['alignment' => Jc::CENTER])
            );
            $table->addCell(self::CRED_COLS[4], ['vAlign' => 'center'])->addText(
                $this->value($row['mot_de_passe'] ?? null),
                ['size' => 11],
                $this->para(['alignment' => Jc::CENTER])
            );
        }

        return $this->save($phpWord);
    }

    /**
     * Download name of the student list, e.g. "Liste stagiaire — Infirmier
     * Polyvalent — 3ème année — 2025/2026.docx". The school year slash is the
     * only character Windows forbids in a file name, so it becomes a hyphen.
     */
    public function buildListeFilename(array $context): string
    {
        return $this->filename('Liste stagiaire', $context);
    }

    /**
     * Download name of the identifiers sheet, sharing the single-table name
     * shape so the two exports of a filière sort together.
     */
    public function buildIdentifiantsFilename(array $context): string
    {
        return $this->filename('Identifiants et mots de passe', $context);
    }

    private function filename(string $title, array $context): string
    {
        $part = static fn ($v) => trim((string) ($v ?? ''));
        $bits = array_filter([
            $title,
            $part($context['filiere'] ?? null),
            $part($context['niveau'] ?? null),
            $part($context['annee_scolaire'] ?? null),
        ], fn ($b) => $b !== '');

        $name = preg_replace('~[/\\\\:*?"<>|]+~', '-', implode(' — ', $bits));

        return rtrim($name, ' .') . '.docx';
    }

    /**
     * Identification block: "Liste stagiaire" title, filière / année scolaire,
     * section / année de formation and the promotion, worded exactly as the
     * reference sheet.
     */
    private function addIdentificationBlock($section, array $group): void
    {
        $table = $section->addTable($this->tableStyle(self::TABLE_W, Jc::LEFT));

        $table->addRow(self::ID_ROW_TITLE, ['height' => self::ID_ROW_TITLE]);
        $table->addCell(array_sum(self::ID_COLS), ['gridSpan' => 2, 'vAlign' => 'center'])->addText(
            'Liste stagiaire',
            ['bold' => true, 'underline' => 'single', 'size' => 14],
            $this->para(['alignment' => Jc::CENTER, 'indentation' => ['left' => 164]])
        );

        $table->addRow(self::ID_ROW_FILIERE, ['height' => self::ID_ROW_FILIERE]);
        $this->labelValue(
            $table->addCell(self::ID_COLS[0], ['vAlign' => 'center']),
            'Filière : ',
            (string) ($group['filiere'] ?? '')
        );
        $this->labelValue(
            $table->addCell(self::ID_COLS[1], ['vAlign' => 'center']),
            'Année Scolaire : ',
            (string) ($group['annee_scolaire'] ?? '')
        );

        $table->addRow(self::ID_ROW_LINE, ['height' => self::ID_ROW_LINE]);
        $this->labelValue(
            $table->addCell(self::ID_COLS[0], ['vAlign' => 'center']),
            'Section : ',
            (string) ($group['section'] ?? '')
        );
        $this->labelValue(
            $table->addCell(self::ID_COLS[1], ['vAlign' => 'center']),
            'Année de formation    : ',
            (string) ($group['niveau'] ?? '')
        );

        $table->addRow(self::ID_ROW_LINE, ['height' => self::ID_ROW_LINE]);
        $this->labelValue(
            $table->addCell(array_sum(self::ID_COLS), ['gridSpan' => 2, 'vAlign' => 'center']),
            'Promotion : ',
            (string) ($group['promotion'] ?? ''),
            Jc::CENTER
        );
    }

    /**
     * Numbered name column of the reference list: "1.", "2.", ... hang in front of
     * the name, and only the name lines up at the 449-twip indent. A definition is
     * registered per group, so every group's list restarts at 1.
     */
    private function addListNumbering(PhpWord $phpWord, string $styleName): void
    {
        $phpWord->addNumberingStyle($styleName, [
            'type'   => 'hybridMultilevel',
            'levels' => [
                [
                    'format'    => 'decimal',
                    'text'      => '%1.',
                    'start'     => 1,
                    'suffix'    => 'tab',
                    'left'      => self::LIST_NAME_INDENT,
                    'hanging'   => self::LIST_NAME_INDENT,
                    'alignment' => 'left',
                    'font'      => self::FONT,
                ],
            ],
        ]);
    }

    /**
     * The student list itself: Nom et Prénom / CIN / N° D'inscription, one row per
     * student, ordered by N° d'inscription as in the reference sheet.
     */
    private function addListTable($section, array $etudiants, string $numbering): void
    {
        $table = $section->addTable($this->tableStyle(self::LIST_W, Jc::CENTER));

        // 14 pt bold headers, centred, repeating on every page.
        $table->addRow(281, ['height' => 281, 'tblHeader' => true]);
        $labels = ['Nom et Prénom', 'CIN', "N° D'inscription"];
        foreach ($labels as $i => $label) {
            $table->addCell(self::LIST_COLS[$i], ['vAlign' => 'center'])->addText(
                $label,
                ['bold' => true, 'size' => 14],
                $this->para(['alignment' => Jc::CENTER])
            );
        }

        foreach ($etudiants as $etudiant) {
            $table->addRow();
            $table->addCell(self::LIST_COLS[0], ['vAlign' => 'center'])->addText(
                $this->value($etudiant['nom_prenom'] ?? null),
                ['size' => 12],
                $this->para([
                    'numStyle'    => $numbering,
                    'numLevel'    => 0,
                    'indentation' => ['left' => self::LIST_NAME_INDENT, 'hanging' => self::LIST_NAME_INDENT],
                ])
            );
            $table->addCell(self::LIST_COLS[1], ['vAlign' => 'center'])->addText(
                $this->value($etudiant['cin'] ?? null),
                ['size' => 12],
                $this->para(['alignment' => Jc::CENTER])
            );
            $table->addCell(self::LIST_COLS[2], ['vAlign' => 'center'])->addText(
                $this->value($etudiant['numero_inscription'] ?? null),
                ['size' => 12],
                $this->para(['alignment' => Jc::CENTER])
            );
        }
    }

    /**
     * The band the reference leaves between the identification block and the
     * list, reproduced with an empty paragraph's spacing.
     */
    private function addBlockGap($section): void
    {
        $section->addText('', ['size' => 8], $this->para(['spaceAfter' => self::BLOCK_GAP]));
    }

    /**
     * The empty paragraph the reference file opens its body with: it holds the
     * block clear of the authorization line printed in the header.
     */
    private function addLeadingGap($section): void
    {
        $section->addText('', ['size' => self::LEADING_SIZE], $this->para());
    }

    private function labelValue($cell, string $label, string $value, string $alignment = Jc::LEFT): void
    {
        $run = $cell->addTextRun($this->para(['alignment' => $alignment]));
        $run->addText($label, ['bold' => true, 'size' => 12]);
        $run->addText($value, ['size' => 12]);
    }

    /**
     * Scope line of the identifiers sheet, listing only the filters that were
     * actually applied.
     */
    private function scopeLabel(array $header): string
    {
        $bits = [];
        foreach ([
            'Filière'        => $header['filiere'] ?? '',
            'Niveau'         => $header['niveau'] ?? '',
            'Année Scolaire' => $header['annee_scolaire'] ?? '',
        ] as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $bits[] = $label . ' : ' . $value;
            }
        }

        return implode('    ', $bits);
    }

    private function value($value): string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? '-' : $value;
    }

    private function newPhpWord(): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName(self::FONT);

        return $phpWord;
    }

    /**
     * One A4-portrait sheet carrying the institute header and footer. Called once
     * per group so every group starts on a fresh page.
     */
    private function addSection(PhpWord $phpWord)
    {
        $section = $phpWord->addSection([
            'orientation' => 'portrait',
            'pageSizeW' => self::PAGE_W,
            'pageSizeH' => self::PAGE_H,
            'marginTop' => self::MARGIN_TOP,
            'marginBottom' => self::MARGIN_BOTTOM,
            'marginLeft' => self::MARGIN_LEFT,
            'marginRight' => self::MARGIN_RIGHT,
            'headerHeight' => self::HEADER_H,
            'footerHeight' => self::FOOTER_H,
        ]);

        $this->addHeader($section);
        $this->addFooter($section);

        return $section;
    }

    /**
     * Header: the institute authorization line, worded as on every other sheet of
     * the application (the reference list file carries the same line, without the
     * logo banner the relevé and bulletin sheets print above it).
     */
    private function addHeader($section): void
    {
        $header = $section->addHeader();
        $header->addText(
            self::AUTH_TEXT,
            ['bold' => true, 'size' => 7, 'name' => self::FONT],
            $this->para([
                'alignment' => Jc::LEFT,
                'spaceBefore' => self::HEADER_SPACE_BEFORE,
                'indentation' => ['left' => self::HEADER_TEXT_INDENT],
            ])
        );
    }

    /**
     * Footer: the institute address and contacts, exactly as in the reference.
     */
    private function addFooter($section): void
    {
        $footer = $section->addFooter();

        $run = $footer->addTextRun($this->para(['alignment' => Jc::CENTER]));
        $run->addText(self::FOOTER_TEXT, ['size' => 7.6, 'name' => self::FONT]);
        $run->addText(self::SITE_URL, [
            'size' => 7.6,
            'name' => self::FONT,
            'color' => '0070C0',
        ]);
    }

    /**
     * Compact paragraph defaults: without explicit zero spacing Word's Normal
     * style adds 8pt space-after to every paragraph and inflates every row.
     */
    private function para(array $extra = []): array
    {
        return $extra + ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1];
    }

    /**
     * Base table style: fixed width, thin single black borders (the reference
     * "Grilledutableau" style, 0.5 pt) and no vertical cell padding.
     */
    private function tableStyle(int $width, string $alignment): array
    {
        return [
            'width' => $width,
            'layout' => TableStyle::LAYOUT_FIXED,
            'alignment' => $alignment,
            'borderSize' => 4,
            'borderColor' => '000000',
            'cellMarginLeft' => 108,
            'cellMarginRight' => 108,
            'cellMarginTop' => 0,
            'cellMarginBottom' => 0,
        ];
    }

    private function save(PhpWord $phpWord): string
    {
        // Two PhpWord settings drive these sheets and are restored afterwards so
        // no other generator of the process inherits them:
        //  - output escaping, because run text is written raw by default and a
        //    password or a name holding an "&" (the password generator's own
        //    symbol set includes one) would produce an invalid .docx;
        //  - the default font size, from which a numbered paragraph's list number
        //    takes its size, so it matches the 12 pt names and not the 10 pt
        //    PhpWord default.
        $escaping = Settings::isOutputEscapingEnabled();
        $fontSize = Settings::getDefaultFontSize();
        Settings::setOutputEscapingEnabled(true);
        Settings::setDefaultFontSize(12);

        try {
            if (!class_exists('ZipArchive')) {
                Settings::setZipClass(Settings::PCLZIP);
            }

            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $tmp = tempnam(sys_get_temp_dir(), 'etudiants_');
            $writer->save($tmp);

            return $tmp;
        } finally {
            Settings::setOutputEscapingEnabled($escaping);
            Settings::setDefaultFontSize($fontSize);
        }
    }
}
