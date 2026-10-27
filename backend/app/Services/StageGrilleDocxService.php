<?php

namespace App\Services;

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;

/**
 * Builds the two printable stage sheets handed to the students.
 *
 * The layout is not redrawn here: it is the institute's own files
 * ("GRILLE EVA IP2.docx" and "GRILLE D'examen Final.docx") reduced to a single
 * sheet and kept as templates under resources/stage, with ${placeholders} in
 * the cells that vary. A sheet is produced by pouring the values into the
 * template's XML, so the printed result is the reference document.
 *
 * A sheet is built per student. In "blank" mode only the identity block and the
 * stage place are printed (the grid is left for handwriting); in "filled" mode
 * the scores saved on the Stage page are printed on top of it. A whole group is
 * exported as one file per student, zipped.
 */
class StageGrilleDocxService
{
    public const EVALUATION = 'grille_evaluation';
    public const EXAMEN = 'grille_examen';

    private const COMPORTEMENT = 5;
    private const APTITUDES = 10;
    private const LIGNES_EXAMEN = 7;

    /**
     * Smallest size (in points) a value is reduced to when it is too long for
     * its line, so the whole "libellé : valeur" line still holds on one line.
     */
    private const TAILLE_MIN_VALEUR = 6.0;

    /**
     * "Grille d'évaluation du stage".
     *
     * @param array<string, mixed> $ident  nom_prenom / numero_inscription / section / filiere / formation / annee_scolaire
     * @param array<string, mixed> $opts   duree / lieu / service / pratique / data (null = blank sheet)
     */
    public function buildEvaluation(array $ident, array $opts): string
    {
        return $this->render('grille_evaluation', $this->evaluationValues($ident, $opts));
    }

    /**
     * The filled values of one "Grille d'évaluation du stage".
     *
     * @param array<string, mixed> $ident
     * @param array<string, mixed> $opts
     * @return array<string, string|null>
     */
    private function evaluationValues(array $ident, array $opts): array
    {
        $data = $opts['data'] ?? null;

        // The saved sheet is a map of {service: grid}: keep the grid of the
        // service being printed.
        if (is_array($data)) {
            $data = $this->selectGrid($data, (string) ($opts['service'] ?? '')) ?? $data;
        }

        // The technique being evaluated: typed at download time, or the one
        // saved on the student's own sheet.
        $pratique = trim((string) ($opts['pratique'] ?? ''));
        if ($pratique === '') {
            $pratique = trim((string) ($data['pratique_executee'] ?? ''));
        }

        $dureeIn = trim((string) ($opts['duree'] ?? ''));
        if ($dureeIn === '') {
            $dureeOut = 'du .../.../.... au .../.../....';
        } elseif (stripos($dureeIn, 'du ') !== false || stripos($dureeIn, 'au ') !== false) {
            $dureeOut = $dureeIn;
        } else {
            $dureeOut = $dureeIn . ' du .../.../.... au .../.../....';
        }

        // The institute form leaves the precise internship dates for handwriting.
        // Keep the optional duration entered from the download dialog, then add
        // the printed date range immediately after it.
        $values = $this->identity($ident) + [
            '${duree}'             => $dureeOut,
            '${lieu}'              => (string) ($opts['lieu'] ?? ''),
            '${service}'           => (string) ($opts['service'] ?? ''),
            '${pratique_executee}' => $pratique,
        ];

        for ($row = 1; $row <= self::COMPORTEMENT; $row++) {
            for ($note = 0; $note <= 4; $note++) {
                $values['${t1_' . $row . '_' . $note . '}'] = '';
            }
        }
        for ($row = 1; $row <= self::APTITUDES; $row++) {
            for ($note = 0; $note <= 4; $note++) {
                $values['${t2_' . $row . '_' . $note . '}'] = '';
            }
        }

        $values += [
            '${score_1}'        => '',
            '${score_2}'        => '',
            '${pratique_1}'     => '',
            '${pratique_2}'     => '',
            '${pratique_score}' => '',
            '${note_globale}'   => '',
            '${decision}'       => '',
            '${valide}'         => '',
            '${invalide}'       => '',
            '${observation}'    => '',
        ];

        if ($data !== null) {
            $comportement = $this->scores($data['comportement'] ?? [], self::COMPORTEMENT);
            $aptitudes = $this->scores($data['aptitudes'] ?? [], self::APTITUDES);

            foreach ($comportement as $index => $note) {
                $values['${t1_' . ($index + 1) . '_' . $note . '}'] = 'X';
            }
            foreach ($aptitudes as $index => $note) {
                $values['${t2_' . ($index + 1) . '_' . $note . '}'] = 'X';
            }

            $score1 = array_sum($comportement);
            $score2 = array_sum($aptitudes);

            $pratique1 = (float) ($data['eval_pratique_1'] ?? 0);
            $pratique2 = (float) ($data['eval_pratique_2'] ?? 0);
            $pratiqueScore = $pratique1 + $pratique2;

            $globale = $pratiqueScore > 0
                ? ($score1 + $score2 + $pratiqueScore) / 5
                : ($score1 + $score2) / 5;

            $values['${score_1}'] = $this->num((float) $score1);
            $values['${score_2}'] = $this->num((float) $score2);
            $values['${pratique_1}'] = $this->num($pratique1);
            $values['${pratique_2}'] = $this->num($pratique2);
            $values['${pratique_score}'] = $this->num($pratiqueScore);
            $values['${note_globale}'] = $this->num($globale);
            $values['${decision}'] = $globale >= 10 ? 'Stage valide' : 'Stage invalide';
            $values['${valide}'] = $globale >= 10 ? 'X' : '';
            $values['${invalide}'] = $globale >= 10 ? '' : 'X';
        }

        return $values;
    }

    /**
     * "Grille d'examen final".
     *
     * @param array<string, mixed> $ident  nom_prenom / numero_inscription / section / filiere / formation / annee_scolaire
     * @param array<string, mixed> $opts   lieu / service / pratique / data (null = blank sheet)
     */
    public function buildExamen(array $ident, array $opts): string
    {
        return $this->render('grille_examen', $this->examenValues($ident, $opts));
    }

    /**
     * The filled values of one "Grille d'examen final".
     *
     * @param array<string, mixed> $ident
     * @param array<string, mixed> $opts
     * @return array<string, string|null>
     */
    private function examenValues(array $ident, array $opts): array
    {
        $data = $opts['data'] ?? null;
        if (!is_array($data)) {
            $data = is_array($opts) ? $opts : [];
        }
        if (!isset($data['lignes']) && isset($opts['lignes']) && is_array($opts['lignes'])) {
            $data['lignes'] = $opts['lignes'];
        }
        $guides = $this->guides();
        $lignes = [];
        if (isset($data['lignes']) && is_array($data['lignes'])) {
            $lignes = $data['lignes'];
        } elseif (isset($data[0]) && is_array($data[0]) && array_key_exists('note', $data[0])) {
            $lignes = $data;
        } else {
            $stack = [$data];
            while (!empty($stack)) {
                $cur = array_pop($stack);
                if (!is_array($cur)) continue;
                if (isset($cur['lignes']) && is_array($cur['lignes'])) {
                    $lignes = $cur['lignes'];
                    break;
                }
                if (isset($cur[0]) && is_array($cur[0]) && array_key_exists('note', $cur[0])) {
                    $lignes = $cur;
                    break;
                }
                foreach ($cur as $v) {
                    if (is_array($v)) $stack[] = $v;
                }
            }
        }

        $values = $this->identity($ident);

        $this->fillLines(
            $values,
            'lieu_service',
            $this->lines($guides, 'lieu_service'),
            $this->lieuService($opts)
        );

        // The technique being examined: typed at download time, or the one saved
        // on the student's own sheet.
        $pratique = trim((string) ($opts['pratique'] ?? ''));
        if ($pratique === '') {
            $pratique = trim((string) ($data['pratique_executee'] ?? ($data['pratique_executée'] ?? ($data['pratique'] ?? ''))));
        }

        $this->fillLines(
            $values,
            'pratique_executee',
            $this->lines($guides, 'pratique_executee'),
            $pratique === '' && $data === null ? null : $pratique
        );

        $total = 0.0;
        $hasNotes = false;

        for ($row = 1; $row <= self::LIGNES_EXAMEN; $row++) {
            $ligne = is_array($lignes[$row - 1] ?? null) ? $lignes[$row - 1] : [];
            $note = $ligne['note'] ?? ($ligne['valeur'] ?? ($ligne['value'] ?? ($ligne['note_examen'] ?? '')));
            $hasNotes = $hasNotes || (is_scalar($note) && trim((string) $note) !== '');

            if (is_numeric($note)) {
                $values['${note_' . $row . '}'] = $this->num((float)$note);
            } elseif (is_scalar($note) && (string)$note !== '') {
                $s = (string)$note;
                $s2 = str_replace(',', '.', $s);
                if (is_numeric($s2)) {
                    $values['${note_' . $row . '}'] = $this->num((float)$s2);
                } else {
                    $values['${note_' . $row . '}'] = $s;
                }
            } else {
                $values['${note_' . $row . '}'] = '';
            }

            $obs = $ligne['observation'] ?? ($ligne['obs'] ?? ($ligne['remarque'] ?? ($ligne['appreciation'] ?? '')));
            $this->fillLines(
                $values,
                'obs_' . $row,
                $this->lines($guides, 'obs_' . $row),
                $data === null ? null : trim((string) $obs)
            );

            if (is_numeric($note)) {
                $total += (float) $note;
            } else {
                $s = (string)$note;
                $s2 = str_replace(',', '.', $s);
                if (is_numeric($s2)) {
                    $total += (float)$s2;
                }
            }
        }

        $values['${total}'] = $data !== null && $hasNotes ? $this->num($total) : '';

        return $values;
    }

    /**
     * Picks one grid out of a legacy {service: grid} map.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>|null
     */
    private function selectGrid(array $map, ?string $service): ?array
    {
        if (isset($map['lignes']) || isset($map['comportement']) || isset($map['aptitudes'])) {
            return $map;
        }

        $wanted = mb_strtolower(trim((string) $service));

        if ($wanted !== '') {
            foreach ($map as $key => $grid) {
                if (is_array($grid) && mb_strtolower(trim((string) $key)) === $wanted) {
                    return $grid;
                }
            }
        }

        foreach ($map as $grid) {
            if (is_array($grid) && (isset($grid['lignes']) || isset($grid['comportement']) || isset($grid['aptitudes']))) {
                return $grid;
            }
        }

        foreach ($map as $grid) {
            if (is_array($grid) && isset($grid[0]) && is_array($grid[0]) && array_key_exists('note', $grid[0])) {
                return ['lignes' => $grid];
            }
        }

        return null;
    }

    /**
     * The "Lieu et Service" line of the exam sheet, e.g. "mooulouya —
     * Réanimation chirurgicale". The two are kept apart by a wide gap so the
     * place and the service are told one from the other at a glance.
     */
    private function lieuService(array $opts): string
    {
        $lieu = trim((string) ($opts['lieu'] ?? ''));
        $service = trim((string) ($opts['service'] ?? ''));

        if ($lieu === '') {
            return $service;
        }

        if ($service === '') {
            return $lieu;
        }

        return $lieu . '        ' . $service;
    }

    /**
     * Download name of a single sheet, e.g.
     * "Grille d'évaluation — Manal BOUARFA.docx".
     */
    public function buildFilename(string $type, array $ident): string
    {
        $label = $type === self::EXAMEN ? 'Grille d\'examen final' : 'Grille d\'évaluation du stage';
        $name = trim($label . ' — ' . trim((string) ($ident['nom_prenom'] ?? '')));

        return $this->safeName($name) . '.docx';
    }

    /**
     * Download name of the group's archive.
     */
    public function buildGroupFilename(string $type, string $niveau, string $groupe): string
    {
        $label = $type === self::EXAMEN ? 'Grilles d\'examen final' : 'Grilles d\'évaluation du stage';

        return $this->safeName(trim($label . ' — ' . $niveau . ' ' . $groupe, ' —')) . '.zip';
    }

    /**
     * Download name of a sheet among a folder of sheets, e.g.
     * "Grille d'évaluation — Manal BOUARFA — Médecine.docx".
     */
    public function buildPartFilename(string $type, array $ident, string $service): string
    {
        $label = $type === self::EXAMEN ? 'Grille d\'examen final' : 'Grille d\'évaluation du stage';
        $name = trim($label . ' — ' . trim((string) ($ident['nom_prenom'] ?? '')) . ' — ' . $service, ' —');

        return $this->safeName($name) . '.docx';
    }

    /**
     * One Word file holding every sheet of the group, one student per page.
     *
     * All the sheets come from the same template, so only their bodies are
     * spliced: the first one keeps its own parts, the others bring their body
     * and a page break.
     *
     * @param array<int, string> $paths  the generated sheets
     */
    public function buildGroupDocument(array $paths): string
    {
        $paths = array_values(array_filter($paths));

        if ($paths === []) {
            throw new \RuntimeException('Aucune grille à rassembler.');
        }

        if (count($paths) === 1) {
            return $paths[0];
        }

        [$head, $body, $sectPr, $tail] = $this->splitBody($this->readDocument($paths[0]));

        foreach (array_slice($paths, 1) as $path) {
            [, $next] = $this->splitBody($this->readDocument($path));

            $body = $this->pageBreak($body) . $next;
        }

        return $this->writeDocument($paths[0], $head . $body . $sectPr . $tail);
    }

    /**
     * One Word file holding every sheet of one student: one per step of his
     * rotation, one per page.
     *
     * The template is read once and the sheets are spliced in memory, so a
     * student costs one copy of the template rather than one per sheet.
     *
     * @param string $type           StageGrilleDocxService::EVALUATION or ::EXAMEN
     * @param array<string, mixed> $ident  identity block values
     * @param array<int, array<string, mixed>> $steps  one per sheet:
     *        lieu / service / duree / pratique / data
     */
    public function buildStudentDocument(string $type, array $ident, array $steps): string
    {
        $steps = array_values($steps);

        if ($steps === []) {
            throw new \RuntimeException('Aucune grille à générer.');
        }

        $template = $type === self::EXAMEN ? self::EXAMEN : self::EVALUATION;
        $path = resource_path('stage/' . $template . '.docx');

        if (!is_file($path)) {
            throw new \RuntimeException('Modèle introuvable : ' . $path);
        }

        $templateDocument = $this->readDocument($path);

        [$head, $body, $sectPr, $tail] = $this->splitBody($templateDocument);

        foreach ($steps as $i => $step) {
            $values = $type === self::EXAMEN
                ? $this->examenValues($ident, (array) $step)
                : $this->evaluationValues($ident, (array) $step);

            [, $next] = $this->splitBody($this->fill($template, $templateDocument, $values));

            $body = $i === 0 ? $next : $this->pageBreak($body) . $next;
        }

        return $this->writeDocument($path, $head . $body . $sectPr . $tail);
    }

    /**
     * @param array<string, string|null> $values
     */
    private function fill(string $template, string $document, array $values): string
    {
        $search = [];
        $replace = [];

        foreach ($values as $placeholder => $value) {
            if ($value === null) {
                continue;
            }
            $search[] = $placeholder;
            $replace[] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        $document = str_replace($search, $replace, $document);

        // Only the evaluation sheet is concerned by the fitting of long values.
        if ($template === self::EVALUATION) {
            $document = $this->fitOverflowingValues($document);
        }

        return $document;
    }

    /**
     * A line "Libellé ..... :  valeur" is printed on the template's own line.
     * When the value is long, Word would wrap it and the second line would fall
     * back under the label, mixing the value with it.
     *
     * Those lines only — the ones that do not fit on a single line — have their
     * value shrunk just enough that the whole line fits, so nothing ever wraps
     * and the value always stays on its own line. The label keeps the sheet's
     * own size, and every line that already fits is left exactly as it is.
     */
    private function fitOverflowingValues(string $document): string
    {
        if (strpos($document, '<w:p') === false) {
            return $document;
        }

        $dom = new \DOMDocument();
        if (!@$dom->loadXML($document)) {
            return $document;
        }

        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $changed = false;

        foreach ($xp->query('//w:p') as $p) {
            $line = $this->valueStart($xp, $p);
            if ($line === null) {
                continue;
            }

            $usable = $this->usableWidth($xp, $p);
            if ($usable <= 0 || $line['width'] <= $usable) {
                continue;
            }

            // The template pushes the colon to the right with long runs of
            // spaces. Tighten those first: it gives the value room without
            // printing it tiny. Only lines that overflow are touched.
            $excess = $line['width'] - $usable;
            $freed = $this->tightenLabelSpaces($xp, $line['label'], $excess);
            if ($freed > 0) {
                $changed = true;
            }

            // What is left for the value once the label and its colon are
            // placed. The value is shrunk just enough to use that room, and
            // never below a readable minimum.
            $labelWidth = max(0, $line['width'] - $line['valueWidth'] - $freed);
            $allowed = $usable - $labelWidth;

            if ($line['valueWidth'] <= 0) {
                continue;
            }

            if ($allowed <= 0) {
                // Even a tightened label leaves nothing for the value: it has
                // to wrap, there is no size that would keep it on one line.
                continue;
            }

            if ($line['valueWidth'] <= $allowed) {
                continue;
            }

            $ratio = $allowed / $line['valueWidth'];

            foreach ($line['value'] as $r) {
                $rPr = $xp->query('./w:rPr', $r)->item(0);
                if (!$rPr) {
                    continue;
                }

                $sz = $xp->query('./w:sz', $rPr)->item(0);
                $current = $sz && (int) $sz->getAttribute('w:val') > 0
                    ? (int) $sz->getAttribute('w:val') / 2
                    : 10.0;

                $size = max(self::TAILLE_MIN_VALEUR, $current * $ratio);
                $size = min($current, $size);
                $half = (int) round($size * 2);

                foreach (['./w:sz', './w:szCs'] as $path) {
                    $node = $xp->query($path, $rPr)->item(0);
                    if ($node && (int) $node->getAttribute('w:val') > 0) {
                        $node->setAttribute('w:val', (string) $half);
                    }
                }
            }

            $changed = true;
        }

        return $changed ? $dom->saveXML() : $document;
    }

    /**
     * Remove the surplus spaces the template uses to place its colon, only as
     * many as needed, and only from the runs before the value.
     *
     * @param list<\DOMElement> $runs
     * @return int width freed, in twips
     */
    private function tightenLabelSpaces(\DOMXPath $xp, array $runs, int $excess): int
    {
        $freed = 0;

        foreach ($runs as $r) {
            if ($freed >= $excess) {
                break;
            }

            $size = 10.0;
            $sz = $xp->query('./w:rPr/w:sz', $r)->item(0);
            if ($sz && (int) $sz->getAttribute('w:val') > 0) {
                $size = (int) $sz->getAttribute('w:val') / 2;
            }

            $unit = max(1, $this->textWidth(' ', $size));

            foreach ($xp->query('.//w:t', $r) as $t) {
                if ($freed >= $excess) {
                    break;
                }

                $text = $t->textContent;
                if (strpos($text, '  ') === false) {
                    continue;
                }

                $need = (int) ceil(($excess - $freed) / $unit);
                $removed = 0;

                $new = preg_replace_callback('/ {2,}/', function (array $m) use (&$need, &$removed): string {
                    $spaces = strlen($m[0]);
                    $canRemove = $spaces - 1;
                    $remove = min($canRemove, max(0, $need));
                    $need -= $remove;
                    $removed += $remove;

                    return str_repeat(' ', $spaces - $remove);
                }, $text);

                if ($new === null || $new === $text) {
                    continue;
                }

                $t->nodeValue = $new;
                $freed += $removed * $unit;
            }
        }

        return $freed;
    }

    /**
     * For a paragraph shaped "Libellé ... :  valeur", what is needed to fit it
     * on one line: where the value starts, the width the whole line takes, the
     * runs that carry the value (so only those may be shrunk), and the runs of
     * the label (so the padding spaces before the colon can be tightened).
     *
     * @return array{start: int, width: int, value: list<\DOMElement>, valueWidth: int, label: list<\DOMElement>}|null
     */
    private function valueStart(\DOMXPath $xp, \DOMElement $p): ?array
    {
        $runs = [];
        $valueRuns = null;

        foreach ($xp->query('./w:r', $p) as $r) {
            $text = '';
            foreach ($xp->query('.//w:t', $r) as $t) {
                $text .= $t->textContent;
            }

            if ($text === '') {
                continue;
            }

            $size = 10.0;
            $sz = $xp->query('./w:rPr/w:sz', $r)->item(0);
            if ($sz && (int) $sz->getAttribute('w:val') > 0) {
                $size = (int) $sz->getAttribute('w:val') / 2;
            }

            $bold = $xp->query('./w:rPr/w:b', $r)->item(0) ? 1.03 : 1.0;
            $runs[] = [$text, $size, $bold, $r];
        }

        $start = 0;
        $seenColon = false;
        $valueText = '';
        $valueWidth = 0;
        $valueElements = [];
        $labelElements = [];

        foreach ($runs as [$text, $size, $bold, $r]) {
            if ($seenColon) {
                $valueText .= $text;
                $valueWidth += $this->textWidth($text, $size) * $bold;
                $valueElements[] = $r;
                continue;
            }

            $colon = strrpos($text, ':');
            if ($colon === false) {
                $start += $this->textWidth($text, $size) * $bold;
                $labelElements[] = $r;
                continue;
            }

            // the label ends at the colon's run; what follows, on the same run,
            // belongs to the value
            $start += $this->textWidth(substr($text, 0, $colon + 1), $size) * $bold;
            $labelElements[] = $r;
            $rest = substr($text, $colon + 1);
            if (trim($rest) === '') {
                $seenColon = true;
                continue;
            }

            $valueText .= $rest;
            $valueWidth += $this->textWidth($rest, $size) * $bold;
            $valueElements[] = $r;
        }

        if ($valueElements === [] || trim($valueText) === '') {
            return null;
        }

        return [
            'start' => (int) round($start),
            'width' => (int) round($start + $valueWidth),
            'value' => $valueElements,
            'valueWidth' => (int) round($valueWidth),
            'label' => $labelElements,
        ];
    }

    /**
     * The text width available to a paragraph, in twips.
     *
     * A paragraph inside a table cell is bounded by that cell, which is what
     * makes a long value wrap even on a wide page; otherwise it is bounded by
     * the page margins. Returns 0 when no width can be worked out.
     */
    private function usableWidth(\DOMXPath $xp, \DOMElement $p): int
    {
        $cell = $xp->query('ancestor::w:tc', $p)->item(0);
        if ($cell) {
            return $this->cellWidth($xp, $cell);
        }

        $size = $xp->query('//w:sectPr/w:pgSz')->item(0);
        if (!$size) {
            return 0;
        }

        $width = (int) $size->getAttribute('w:w');
        $margin = $xp->query('//w:sectPr/w:pgMar')->item(0);
        $left = $margin ? (int) $margin->getAttribute('w:left') : 0;
        $right = $margin ? (int) $margin->getAttribute('w:right') : 0;

        return $width - $left - $right;
    }

    /**
     * The inner width of a table cell, in twips.
     */
    private function cellWidth(\DOMXPath $xp, \DOMElement $cell): int
    {
        $width = 0;

        $tcW = $xp->query('./w:tcPr/w:tcW', $cell)->item(0);
        if ($tcW && $tcW->getAttribute('w:type') !== 'pct') {
            $width = (int) $tcW->getAttribute('w:w');
        }

        if ($width <= 0) {
            $grid = $xp->query('ancestor::w:tbl/w:tblGrid', $cell)->item(0);
            $index = 0;
            $previous = $xp->query('preceding-sibling::w:tc', $cell);
            foreach ($previous as $before) {
                $span = $xp->query('./w:tcPr/w:gridSpan', $before)->item(0);
                $index += $span ? max(1, (int) $span->getAttribute('w:val')) : 1;
            }

            $span = $xp->query('./w:tcPr/w:gridSpan', $cell)->item(0);
            $span = $span ? max(1, (int) $span->getAttribute('w:val')) : 1;

            if ($grid) {
                $cols = $xp->query('./w:gridCol', $grid);
                for ($i = $index; $i < $index + $span && $i < $cols->length; $i++) {
                    $width += (int) $cols->item($i)->getAttribute('w:w');
                }
            }
        }

        if ($width <= 0) {
            return 0;
        }

        // a cell's own left/right margins eat into the text area
        $cellMar = $xp->query('ancestor::w:tbl/w:tblPr/w:tblCellMar', $cell)->item(0);
        $left = 0;
        $right = 0;
        if ($cellMar) {
            $l = $xp->query('./w:left', $cellMar)->item(0);
            $r = $xp->query('./w:right', $cellMar)->item(0);
            $left = $l ? (int) $l->getAttribute('w:w') : 0;
            $right = $r ? (int) $r->getAttribute('w:w') : 0;
        }

        return max(0, $width - $left - $right);
    }

    /**
     * The width of a text at a font size, in twips, for Century Gothic.
     *
     * Only used to tell a line that fits from a line that wraps: the advances
     * are the real Century Gothic ones (measured once, baked in so no font file
     * is needed at runtime), and an unknown glyph falls back to an average.
     */
    private function textWidth(string $text, float $size): int
    {
        static $advance = [
            ' ' => 370, ':' => 170, '.' => 170, "'" => 100, '’' => 100, ',' => 200,
            'A' => 930, 'B' => 740, 'C' => 940, 'D' => 1020, 'E' => 780, 'F' => 700,
            'G' => 940, 'H' => 800, 'I' => 260, 'J' => 400, 'K' => 850, 'L' => 620,
            'M' => 1080, 'N' => 760, 'O' => 1020, 'P' => 610, 'Q' => 1020, 'R' => 620,
            'S' => 570, 'T' => 1080, 'U' => 780, 'V' => 1040, 'W' => 1350, 'X' => 720,
            'Y' => 1100, 'Z' => 1040,
            'a' => 750, 'b' => 750, 'c' => 730, 'd' => 740, 'e' => 740, 'f' => 350,
            'g' => 750, 'h' => 620, 'i' => 160, 'j' => 260, 'k' => 580, 'l' => 100,
            'm' => 1060, 'n' => 620, 'o' => 750, 'p' => 740, 'q' => 750, 'r' => 370,
            's' => 430, 't' => 370, 'u' => 630, 'v' => 670, 'w' => 1100, 'x' => 660,
            'y' => 680, 'z' => 570,
        ];

        $units = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $units += $advance[$char] ?? 620;
        }

        return (int) round($units / 1000 * $size * 20);
    }

    /**
     * An archive holding one Word file per entry, e.g. one per student.
     *
     * The entries are named by the caller; a duplicate name is made unique so
     * nothing is silently overwritten.
     *
     * @param array<string, string> $files  entry name => path of the generated file
     */
    public function buildArchive(array $files): string
    {
        if ($files === []) {
            throw new \RuntimeException('Aucune grille à rassembler.');
        }

        $target = tempnam(sys_get_temp_dir(), 'stagezip_');
        @unlink($target);

        $out = new ZipArchive();
        if ($out->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Archive illisible : ' . $target);
        }

        $used = [];

        foreach ($files as $name => $path) {
            $data = @file_get_contents($path);
            if ($data === false) {
                continue;
            }

            $name = $this->uniqueEntry($name, $used);

            $out->addFromString($name, $data);
        }

        $out->close();

        return $target;
    }

    /**
     * A name that no earlier entry of the archive already uses.
     *
     * @param array<string, bool> $used
     */
    private function uniqueEntry(string $name, array &$used): string
    {
        if (!isset($used[$name])) {
            $used[$name] = true;

            return $name;
        }

        $base = preg_replace('/\.docx$/i', '', $name);
        $i = 2;

        while (isset($used[$base . ' (' . $i . ').docx'])) {
            $i++;
        }

        $name = $base . ' (' . $i . ').docx';
        $used[$name] = true;

        return $name;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rendering
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Pours the values into the template and writes the sheet.
     *
     * @param array<string, string|null> $values  null keeps the template's own text
     */
    private function render(string $template, array $values): string
    {
        $path = resource_path('stage/' . $template . '.docx');

        if (!is_file($path)) {
            throw new \RuntimeException('Modèle introuvable : ' . $path);
        }

        // PCLZIP cannot write into an existing file, so the sheet is built
        // from scratch, keeping every part of the template but its document.
        return $this->writeDocument($path, $this->fill($template, $this->readDocument($path), $values));
    }

    /**
     * The word/document.xml of a sheet, or of the template.
     */
    private function readDocument(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            Settings::setZipClass(Settings::PCLZIP);
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Grille illisible : ' . $path);
        }

        $document = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($document === false) {
            throw new \RuntimeException('Grille incomplète : ' . $path);
        }

        return $document;
    }

    /**
     * A copy of a sheet, holding another document.
     */
    private function writeDocument(string $source, string $document): string
    {
        $zip = new ZipArchive();
        if ($zip->open($source, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Grille illisible : ' . $source);
        }

        $target = tempnam(sys_get_temp_dir(), 'stagegrille_');
        @unlink($target);

        $out = new ZipArchive();
        $out->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $data = $name === 'word/document.xml' ? $document : $zip->getFromName($name);

            // PHPWord's PCLZIP chokes on empty entries (directory markers).
            if ($data === false || $data === '' || substr($name, -1) === '/') {
                continue;
            }

            $out->addFromString($name, $data);
        }

        $zip->close();
        $out->close();

        return $target;
    }

    /**
     * A sheet's document, split into head, body, section properties and tail.
     *
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function splitBody(string $xml): array
    {
        $open = strpos($xml, '<w:body>');
        $close = strrpos($xml, '</w:body>');

        if ($open === false || $close === false || $close < $open) {
            throw new \RuntimeException('Document sans corps.');
        }

        $start = $open + strlen('<w:body>');
        $head = substr($xml, 0, $start);
        $inner = substr($xml, $start, $close - $start);
        $tail = substr($xml, $close);

        $sectPr = strrpos($inner, '<w:sectPr');

        if ($sectPr === false) {
            return [$head, $inner, '', $tail];
        }

        return [$head, substr($inner, 0, $sectPr), substr($inner, $sectPr), $tail];
    }

    /**
     * The same body, with a page break at its end: the next sheet starts on a
     * fresh page, at the very top, without an empty line of its own.
     */
    private function pageBreak(string $body): string
    {
        $break = '<w:r><w:br w:type="page"/></w:r>';

        $endParagraph = strrpos($body, '</w:p>');
        $endTable = strrpos($body, '</w:tbl>');

        if ($endParagraph !== false && ($endTable === false || $endParagraph > $endTable)) {
            return substr($body, 0, $endParagraph) . $break . substr($body, $endParagraph);
        }

        return $body . '<w:p>' . $break . '</w:p>';
    }

    /**
     * The dotted guides the blank exam form is written on. They belong to the
     * template's design, so they are captured from it rather than retyped: one
     * entry per line the sheet gives a value.
     *
     * @return array<string, array<int, string>>
     */
    private function guides(): array
    {
        $path = resource_path('stage/grille_guides.json');

        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);
        $guides = is_array($data['examen'] ?? null) ? $data['examen'] : [];

        $out = [];
        foreach ($guides as $key => $lines) {
            $out[$key] = array_map(
                fn ($line) => (string) $line,
                array_values(is_array($lines) ? $lines : [$lines])
            );
        }

        return $out;
    }

    /**
     * @param array<string, array<int, string>> $guides
     * @return array<int, string>
     */
    private function lines(array $guides, string $key): array
    {
        return $guides[$key] ?? [''];
    }

    /**
     * Spreads a value over the lines the sheet gives it: the first line takes
     * the value, the following ones are cleared. On a blank sheet (null) every
     * line keeps its dotted guide, and so does an empty value.
     *
     * @param array<string, string> $values
     * @param array<int, string>    $lines
     */
    private function fillLines(array &$values, string $key, array $lines, ?string $value): void
    {
        foreach (array_values($lines) ?: [''] as $i => $guide) {
            $values[$i === 0 ? '${' . $key . '}' : '${' . $key . '_' . ($i + 1) . '}'] = match (true) {
                $i > 0 && $value !== null  => '',
                $i > 0                     => $guide,
                $value !== null && $value !== '' => $value,
                default                    => $guide,
            };
        }
    }

    /**
     * @param array<string, mixed> $ident
     * @return array<string, string>
     */
    private function identity(array $ident): array
    {
        return [
            '${nom_prenom}'         => (string) ($ident['nom_prenom'] ?? ''),
            '${numero_inscription}' => (string) ($ident['numero_inscription'] ?? ''),
            '${section}'            => (string) ($ident['section'] ?? ''),
            '${filiere}'            => (string) ($ident['filiere'] ?? ''),
            '${formation}'          => (string) ($ident['formation'] ?? ''),
            '${annee_scolaire}'     => (string) ($ident['annee_scolaire'] ?? ''),
        ];
    }

    /**
     * The 0-4 marks of one section, missing entries counted as 0.
     *
     * @param mixed $values
     * @return array<int, int>
     */
    private function scores($values, int $count): array
    {
        $values = is_array($values) ? array_values($values) : [];

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[$i] = max(0, min(4, (int) ($values[$i] ?? 0)));
        }

        return $out;
    }

    private function num(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('~[/\\\\:*?"<>|]+~', '-', $name);

        return rtrim($name, ' .');
    }
}
