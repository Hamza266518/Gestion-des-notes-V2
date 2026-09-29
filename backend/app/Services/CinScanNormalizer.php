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
        $cinAr         = $this->normalizeCin($student['cin_ar'] ?? null);
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

        if ($nationaliteAr === '') {
            $nationaliteAr = $nationalite === self::NATIONALITE_FR ? self::NATIONALITE_AR : $nationalite;
        }

        $numeroInscription = $this->toWesternDigits((string) ($student['numero_inscription'] ?? ''));

        return [
            'nom_prenom'          => $this->normalizeName($student['nom_prenom'] ?? null),
            'nom_ar'              => $this->cleanText($student['nom_ar'] ?? null),
            'cin'                 => $cin,
            'cin_ar'              => $cinAr !== '' ? $cinAr : $cin,
            'date_naissance'      => $dateNaissance,
            'date_naissance_ar'   => $dateNaissanceAr,
            'lieu_naissance'      => $lieuNaissance,
            'lieu_naissance_ar'   => $lieuNaissanceAr,
            'nationalite'         => $nationalite,
            'nationalite_ar'      => $nationaliteAr,
            'date_inscription'    => $this->normalizeDate($student['date_inscription'] ?? null),
            'date_inscription_ar' => $this->cleanText($student['date_inscription_ar'] ?? null),
            'numero_inscription'  => $numeroInscription,
            'numero_inscription_ar' => $numeroInscription,
        ];
    }

    private function cleanText(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? '' : $this->toWesternDigits($value);
    }

    private function isoDate(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
