<?php

namespace App\Services;

class NoteParserService
{
    /**
     * Tokens an OCR/AI read can emit to mean "the student was not there".
     * Compared after normalisation, so accents/case/dots do not matter.
     */
    private const ABSENT_TOKENS = [
        'ab', 'abs', 'absent', 'absente', 'absents', 'absentes',
        'a', 'aj', 'n a', 'na', 'nn', 'non', 'justifie', 'justifiee',
        'neant', 'neant e', 'dash', 'dashs', 'x', 'xx',
    ];

    private const BLANK_TOKENS = ['', '-', '--', '---', '...', '/', 'nc', 'neant'];

    /**
     * Absence markers that may appear alongside a number, e.g. "15.00 (AB)".
     * Word-boundaried so a plain grade is never mistaken for an absence.
     */
    private const ABSENT_MARKER_RE = '/\b(ab|abs|absent|absente|absents|absentes|justifie|justifiee|non|neant)\b/u';

    public function parseNotes(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);
        return is_array($data) ? $data : [];
    }

    public function parseCin(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);
        return is_array($data) ? $data : [];
    }

    public function parseExamens(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Normalise a raw scanned/manual grade value.
     *
     * Returns:
     *   est_absent  true  -> the value means "absent"; valeur is forced to 0
     *   est_absent  false -> a real grade was parsed into valeur
     *   invalid     true  -> nothing usable (valeur null); caller must NOT save a 0
     *
     * Handles "12,5" (Moroccan/French decimal comma), "14/20", " 14 ", "12.5",
     * and absence tokens. A bare 0 is a real grade, never an absence.
     */
    public function parseValue($raw, float $noteMax = 20.0): array
    {
        if ($raw === null) {
            return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
        }

        if (is_int($raw) || is_float($raw)) {
            $value = (float) $raw;
            return $this->clamp($value, $noteMax);
        }

        $text = trim((string) $raw);
        $norm = mb_strtolower($text);
        $norm = str_replace(['.', '’', "'"], ['', ' ', ' '], $norm);
        $norm = trim(preg_replace('/\s+/', ' ', $norm));
        $norm = str_replace(['é', 'è', 'ê'], 'e', $norm);

        // "N/A" -> "n a" so it matches the pure absence token below. Kept separate
        // from $norm because slashes are meaningful in "14/20".
        $noSlash = trim(preg_replace('/\s+/', ' ', str_replace('/', ' ', $norm)));

        // A pure absence token.
        if (in_array($norm, self::ABSENT_TOKENS, true) || in_array($noSlash, self::ABSENT_TOKENS, true)) {
            return ['valeur' => 0.0, 'est_absent' => true, 'invalid' => false];
        }

        // Blank / nothing written on the sheet: needs a human, not a silent 0.
        if (in_array($norm, self::BLANK_TOKENS, true)) {
            return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
        }

        // An absence word sitting next to a number, e.g. "15.00 (AB)".
        // The marker wins: a student flagged absent is absent even if a stray
        // number was also read off the sheet.
        if (preg_match(self::ABSENT_MARKER_RE, $norm)) {
            return ['valeur' => 0.0, 'est_absent' => true, 'invalid' => false];
        }

        // "14/20", "14 sur 20", "14,5/20"
        if (preg_match('#^(\d+(?:[.,]\d+)?)\s*(?:/|sur)\s*(\d+(?:[.,]\d+)?)#', $norm, $m)) {
            $den = (float) str_replace(',', '.', $m[2]);
            $num = (float) str_replace(',', '.', $m[1]);
            if ($den > 0) {
                $noteMax = $den;
                $value = $num;
                return $this->clamp($value, $noteMax);
            }
        }

        // Decimal comma -> dot, then a plain number.
        $candidate = str_replace(',', '.', $text);
        if (is_numeric($candidate)) {
            return $this->clamp((float) $candidate, $noteMax);
        }

        // A number embedded in noise, e.g. "15.00 (AB)" or "note: 12,5".
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $text, $m)) {
            $candidate = str_replace(',', '.', $m[1]);
            if (is_numeric($candidate)) {
                return $this->clamp((float) $candidate, $noteMax);
            }
        }

        return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
    }

    private function clamp(float $value, float $noteMax): array
    {
        $value = round($value, 2);
        if ($value < 0) {
            $value = 0.0;
        }
        if ($value > $noteMax) {
            $value = $noteMax;
        }
        return ['valeur' => $value, 'est_absent' => false, 'invalid' => false];
    }
}