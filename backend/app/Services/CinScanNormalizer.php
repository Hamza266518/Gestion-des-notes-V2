<?php

namespace App\Services;

class CinScanNormalizer
{
    public const NATIONALITE_FR = 'MAROCAINE';
    public const NATIONALITE_AR = 'مغربية';

    protected const ARABIC_MONTHS = [
        1  => ['يانفبر', 'يناير'],
        2  => ['فبراير'],
        3  => ['مارس'],
        4  => ['أبريل', 'ابريل'],
        5  => ['ماي', 'مايو'],
        6  => ['يونيو', 'يونية'],
        7  => ['يوليوز', 'يوليو'],
        8  => ['غشت', 'أغسطس'],
        9  => ['شتنبر', 'سبتمبر'],
        10 => ['أكتوبر'],
        11 => ['نونبر', 'نوفمبر'],
        12 => ['دجنبر', 'ديسمبر'],
    ];

    public function toWesternDigits(string $value): string
    {
        $out = '';
        $len = mb_strlen($value, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($value, $i, 1, 'UTF-8');
            $cp = mb_ord($ch, 'UTF-8');

            if ($cp >= 0x0660 && $cp <= 0x0669) {
                $out .= (string) ($cp - 0x0660);
            } elseif ($cp >= 0x06F0 && $cp <= 0x06F9) {
                $out .= (string) ($cp - 0x06F0);
            } else {
                $out .= $ch;
            }
        }

        return $out;
    }

    public function normalizeCin(?string $value): string
    {
        $value = $this->toWesternDigits((string) $value);
        $value = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return strtoupper($value);
    }

    public function normalizeName(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_strtoupper($value, 'UTF-8');
    }

    public function normalizeDate(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $text = trim($this->toWesternDigits($raw));

        if (preg_match('#(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})#', $text, $m)) {
            return $this->isoDate((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})#', $text, $m)) {
            return $this->isoDate((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return $this->parseArabicDate($raw);
    }

    public function parseArabicDate(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $text = $this->toWesternDigits($raw);

        if (!preg_match('/(\d{1,2})\s*([^\d\s]{2,})\s*(\d{4})/u', $text, $m)) {
            return null;
        }

        $day   = (int) $m[1];
        $month = $this->monthFromArabicName($m[2]);
        $year  = (int) $m[3];

        if ($month === null) {
            return null;
        }

        return $this->isoDate($year, $month, $day);
    }

    public function monthFromArabicName(string $name): ?int
    {
        $folded = $this->foldArabic($name);

        foreach (self::ARABIC_MONTHS as $number => $names) {
            foreach ($names as $candidate) {
                if (str_contains($folded, $this->foldArabic($candidate))) {
                    return $number;
                }
            }
        }

        return null;
    }

    private function foldArabic(string $value): string
    {
        return str_replace(['أ', 'إ', 'آ', 'ى'], ['ا', 'ا', 'ا', 'ي'], $value);
    }

    public function toArabicDate(?string $isoDate): string
    {
        if (!$isoDate || !preg_match('/(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return '';
        }

        $month = (int) $m[2];
        if ($month < 1 || $month > 12) {
            return '';
        }

        $name = self::ARABIC_MONTHS[$month][0];
        $day  = (int) $m[3];
        $year = (int) $m[1];

        return $day . ' ' . $name . ' ' . $year;
    }

    public function normalize(array $student): array
    {
        $cin           = $this->normalizeCin($student['cin'] ?? null);
        $dateNaissance = $this->normalizeDate($student['date_naissance'] ?? null)
            ?? $this->parseArabicDate($student['date_naissance_ar'] ?? null);

        $dateNaissanceAr = $this->cleanText($student['date_naissance_ar'] ?? null);
        if ($dateNaissanceAr === '' || $this->parseArabicDate($dateNaissanceAr) !== $dateNaissance) {
            $dateNaissanceAr = $this->toArabicDate($dateNaissance);
        }

        $lieuNaissance   = $this->cleanText($student['lieu_naissance'] ?? null);
        $lieuNaissanceAr = $this->cleanText($student['lieu_naissance_ar'] ?? null);

        $nationalite   = $this->cleanText($student['nationalite'] ?? null);
        $nationaliteAr = $this->cleanText($student['nationalite_ar'] ?? null);

        if ($nationalite === '') {
            $nationalite = self::NATIONALITE_FR;
        }

        // The student body is almost entirely Moroccan: any obvious spelling of
        // "Marocaine" (accents, doubled letters, stray affixes, even the Arabic
        // word) collapses to the canonical value.
        if ($this->isMoroccanNationality($nationalite)) {
            $nationalite = self::NATIONALITE_FR;
            // The Arabic box must hold Arabic script. When it is empty or the
            // scan only produced Latin text, مغربية replaces it.
            if ($nationaliteAr === '' || !$this->hasArabicScript($nationaliteAr)) {
                $nationaliteAr = self::NATIONALITE_AR;
            }
        }

        $dateInscription   = $this->normalizeDate($student['date_inscription'] ?? null)
            ?? $this->parseArabicDate($student['date_inscription_ar'] ?? null);
        $dateInscriptionAr = $this->cleanText($student['date_inscription_ar'] ?? null);
        if ($dateInscriptionAr === '' || $this->parseArabicDate($dateInscriptionAr) !== $dateInscription) {
            $dateInscriptionAr = $this->toArabicDate($dateInscription);
        }

        $numeroInscription = $this->toWesternDigits((string) ($student['numero_inscription'] ?? ''));

        return [
            'nom_prenom'          => $this->normalizeName($student['nom_prenom'] ?? null),
            'nom_ar'              => $this->cleanText($student['nom_ar'] ?? null),
            'cin'                 => $cin,
            'cin_ar'              => $cin,
            'date_naissance'      => $dateNaissance,
            'date_naissance_ar'   => $dateNaissanceAr,
            'lieu_naissance'      => $lieuNaissance,
            'lieu_naissance_ar'   => $lieuNaissanceAr,
            'nationalite'         => $nationalite,
            'nationalite_ar'      => $nationaliteAr,
            'date_inscription'    => $dateInscription,
            'date_inscription_ar' => $dateInscriptionAr,
            'numero_inscription'  => $numeroInscription,
            'numero_inscription_ar' => $numeroInscription,
        ];
    }

    private function cleanText(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $this->toWesternDigits($value);
    }

    private function isMoroccanNationality(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if ($this->hasArabicScript($value)) {
            return str_contains($this->foldArabic($value), 'مغرب');
        }

        $folded = mb_strtolower($value, 'UTF-8');
        $folded = strtr($folded, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ò' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $folded = preg_replace('/[^a-z0-9]/', '', $folded) ?? $folded;

        return str_contains($folded, 'maroc') || str_contains($folded, 'moroc');
    }

    private function hasArabicScript(string $value): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}]/u', $value);
    }

    private function isoDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
