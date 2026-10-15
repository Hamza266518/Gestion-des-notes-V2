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
        return $this->decodeList($rawText);
    }

    public function parseCin(string $rawText): array
    {
        return $this->decodeList($rawText);
    }

    public function parseExamens(string $rawText): array
    {
        return $this->decodeList($rawText);
    }

    /**
     * Same decoding as the other scans: one record per student, each carrying a
     * "notes" array aligned to the jury criteria given in the scan context.
     */
    public function parseSoutenance(string $rawText): array
    {
        return $this->decodeList($rawText);
    }

    /**
     * Decode the model's JSON text into a list of records.
     *
     * A model that answers with a single object instead of an array used to be
     * returned as-is, and its field values were then iterated as if each were a
     * record (the "string given where array expected" failure). Wrapping the
     * object makes it exactly one record.
     */
    private function decodeList(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);

        if (!is_array($data)) {
            return [];
        }

        return array_is_list($data) ? $data : [$data];
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
            if ($this->tropDeChiffres((string) $value, $noteMax)) {
                return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
            }
            if (!$this->isValidQuarterStep($value)) {
                return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
            }
            return $this->clamp($value, $noteMax);
        }

        $text = trim((string) $raw);
        $norm = mb_strtolower($text);
        $norm = str_replace(['.', '’', "'"], ['', ' ', ' '], $norm);
        $norm = trim(preg_replace('/\s+/', ' ', $norm));
        $norm = str_replace(['é', 'è', 'ê'], 'e', $norm);

        // Same normalisation but dots survive, because a dot is the decimal point
        // in "14.5/20". Using $norm here would turn that into "145/20" and clamp
        // a 14.5 up to a perfect 20. The dot-stripped $norm stays for the token
        // and marker checks below, where dropping sentence punctuation is correct.
        $fracNorm = mb_strtolower($text);
        $fracNorm = str_replace(['’', "'"], [' ', ' '], $fracNorm);
        $fracNorm = trim(preg_replace('/\s+/', ' ', $fracNorm));
        $fracNorm = str_replace(['é', 'è', 'ê'], 'e', $fracNorm);

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

        // "14/20", "14 sur 20", "14,5/20", "14.5/20"
        if (preg_match('#^(\d+(?:[.,]\d+)?)\s*(?:/|sur)\s*(\d+(?:[.,]\d+)?)#', $fracNorm, $m)) {
            $den = (float) str_replace(',', '.', $m[2]);
            $num = (float) str_replace(',', '.', $m[1]);
            if ($den > 0) {
                // A numerator above the denominator means the read is incoherent
                // ("145/20", "14/2"), not that the student scored full marks.
                // Clamping would silently award a perfect grade, so hand it to a human.
                if ($num > $den) {
                    return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
                }
                if (!$this->isValidQuarterStep($num)) {
                    return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
                }
                $noteMax = $den;
                $value = $num;
                return $this->clamp($value, $noteMax);
            }
        }

        // Decimal comma -> dot, then a plain number.
        $candidate = str_replace(',', '.', $text);
        if (is_numeric($candidate)) {
            if ($this->tropDeChiffres($candidate, $noteMax)) {
                return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
            }
            if (!$this->isValidQuarterStep((float) $candidate)) {
                return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
            }
            return $this->clamp((float) $candidate, $noteMax);
        }

        // A number embedded in noise, e.g. "15.00 (AB)" or "note: 12,5".
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $text, $m)) {
            $candidate = str_replace(',', '.', $m[1]);
            if (is_numeric($candidate)) {
                if ($this->tropDeChiffres($candidate, $noteMax)) {
                    return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
                }
                if (!$this->isValidQuarterStep((float) $candidate)) {
                    return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
                }
                return $this->clamp((float) $candidate, $noteMax);
            }
        }

        return ['valeur' => null, 'est_absent' => false, 'invalid' => true];
    }

    /**
     * A bare number with more integer digits than the maximum is a misread
     * ("145" for "14.5"), not a perfect score: clamping would silently award
     * full marks, so it is handed to a human instead. The check is on the digit
     * count, not the value, so a genuine grade like "25" on /20 is still clamped
     * to 20 (both are two digits) while "145" is refused.
     */
    private function tropDeChiffres(string $number, float $noteMax): bool
    {
        // The caller using PHP_FLOAT_MAX wants the value untouched; it performs
        // its own upper-bound check.
        if ($noteMax >= PHP_FLOAT_MAX) {
            return false;
        }

        $entier = (string) abs((float) $number);
        $entier = ltrim(explode('.', $entier)[0], '0');

        $maxEntier = ltrim((string) (int) floor($noteMax), '0');

        return strlen($entier) > strlen($maxEntier);
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

    /**
     * A grade is only ever a quarter step: 0, 0.25, 0.5, 0.75, 1, ... A reading
     * like 14.7 or 14.9 is a misread of the sheet and must be corrected by hand,
     * so parseValue refuses it instead of rounding it silently.
     */
    public function isValidQuarterStep(float $value): bool
    {
        // Magnitude only: a negative read ("-3") is clamped to 0 by clamp(),
        // exactly as before, and only a non-quarter magnitude is refused.
        $steps = abs($value) * 4.0;
        $rounded = round($steps);
        return abs($steps - $rounded) < 1e-4;
    }
}