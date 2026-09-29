<?php

namespace Tests\Unit;

use App\Services\CinScanNormalizer;
use Tests\TestCase;

class CinScanNormalizerTest extends TestCase
{
    protected CinScanNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new CinScanNormalizer();
    }

    public function test_arabic_indic_digits_are_converted_to_western(): void
    {
        $this->assertSame('1998', $this->normalizer->toWesternDigits('١٩٩٨'));
        $this->assertSame('AB123456', $this->normalizer->toWesternDigits('AB١٢٣٤٥٦'));
        $this->assertSame('15/05/1998', $this->normalizer->toWesternDigits('١٥/٠٥/١٩٩٨'));
    }

    public function test_extended_arabic_indic_digits_are_converted(): void
    {
        $this->assertSame('2024', $this->normalizer->toWesternDigits('۲۰۲۴'));
    }

    public function test_cin_is_uppercased_and_stripped_of_separators(): void
    {
        $this->assertSame('AB123456', $this->normalizer->normalizeCin('ab-123 456'));
        $this->assertSame('BE112233', $this->normalizer->normalizeCin('be112233'));
        $this->assertSame('', $this->normalizer->normalizeCin('  '));
    }

    public function test_iso_date_is_returned_unchanged(): void
    {
        $this->assertSame('1998-05-15', $this->normalizer->normalizeDate('1998-05-15'));
    }

    public function test_day_first_date_is_converted_to_iso(): void
    {
        $this->assertSame('1998-05-15', $this->normalizer->normalizeDate('15/05/1998'));
    }

    public function test_arabic_month_names_are_parsed(): void
    {
        $this->assertSame('1998-05-15', $this->normalizer->parseArabicDate('15 ماي 1998'));
        $this->assertSame('2001-01-09', $this->normalizer->parseArabicDate('9 يانفبر 2001'));
    }

    public function test_moroccan_arabic_month_names_are_parsed(): void
    {
        $this->assertSame('2023-09-15', $this->normalizer->parseArabicDate('15 شتنبر 2023'));
        $this->assertSame('2023-07-04', $this->normalizer->parseArabicDate('4 يوليوز 2023'));
        $this->assertSame('2023-08-20', $this->normalizer->parseArabicDate('20 غشت 2023'));
        $this->assertSame('2023-12-01', $this->normalizer->parseArabicDate('1 دجنبر 2023'));
        $this->assertSame('2023-11-05', $this->normalizer->parseArabicDate('5 نونبر 2023'));
    }

    public function test_modern_standard_arabic_month_names_are_also_parsed(): void
    {
        $this->assertSame('2023-09-15', $this->normalizer->parseArabicDate('15 سبتمبر 2023'));
        $this->assertSame('2023-07-04', $this->normalizer->parseArabicDate('4 يوليو 2023'));
        $this->assertSame('2023-12-01', $this->normalizer->parseArabicDate('1 ديسمبر 2023'));
    }

    public function test_october_is_parsed_despite_hamza(): void
    {
        $this->assertSame('2023-10-10', $this->normalizer->parseArabicDate('10 أكتوبر 2023'));
        $this->assertSame('2023-10-10', $this->normalizer->parseArabicDate('10 اكتوبر 2023'));
    }

    public function test_every_month_round_trips_through_its_generated_arabic_name(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $iso = sprintf('2000-%02d-15', $month);
            $arabic = $this->normalizer->toArabicDate($iso);

            $this->assertSame($iso, $this->normalizer->parseArabicDate($arabic), "month $month");
        }
    }

    public function test_arabic_date_with_arabic_digits_is_parsed(): void
    {
        $this->assertSame('1998-05-15', $this->normalizer->parseArabicDate('١٥ مايو ١٩٩٨'));
    }

    public function test_unparseable_date_returns_null(): void
    {
        $this->assertNull($this->normalizer->parseArabicDate('not a date'));
        $this->assertNull($this->normalizer->parseArabicDate(''));
    }

    public function test_impossible_date_returns_null(): void
    {
        $this->assertNull($this->normalizer->normalizeDate('1998-02-31'));
        $this->assertNull($this->normalizer->normalizeDate('31/13/1998'));
    }

    public function test_arabic_date_is_generated_from_iso_date(): void
    {
        $this->assertSame('15 ماي 1998', $this->normalizer->toArabicDate('1998-05-15'));
        $this->assertSame('15 شتنبر 2023', $this->normalizer->toArabicDate('2023-09-15'));
        $this->assertSame('1 دجنبر 2023', $this->normalizer->toArabicDate('2023-12-01'));
        $this->assertSame('', $this->normalizer->toArabicDate(null));
    }

    public function test_moroccan_month_spelling_is_preserved_when_it_matches(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom'        => 'HAJIB SALMA',
            'cin'               => 'AB123456',
            'date_naissance'    => '2023-07-04',
            'date_naissance_ar' => '4 يوليوز 2023',
        ]);

        $this->assertSame('4 يوليوز 2023', $data['date_naissance_ar']);
    }

    public function test_arabic_date_is_regenerated_when_it_disagrees_with_latin(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom'        => 'HAJIB SALMA',
            'cin'               => 'AB123456',
            'date_naissance'    => '2023-07-04',
            'date_naissance_ar' => '9 يوليوز 2023',
        ]);

        $this->assertSame('4 يوليوز 2023', $data['date_naissance_ar']);
    }

    public function test_nationality_defaults_to_moroccan_in_both_languages(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom' => 'FATIMA ZAHRA IDRISSI',
            'cin'        => 'AB123456',
        ]);

        $this->assertSame('MAROCAINE', $data['nationalite']);
        $this->assertSame('مغربية', $data['nationalite_ar']);
    }

    public function test_arabic_cin_mirrors_latin_cin(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom' => 'Youssef Amrani',
            'cin'        => 'be-112233',
        ]);

        $this->assertSame('BE112233', $data['cin']);
        $this->assertSame('BE112233', $data['cin_ar']);
    }

    public function test_birth_date_is_derived_from_arabic_when_latin_missing(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom'        => 'SALMA BENALI',
            'cin'               => 'JK987654',
            'date_naissance_ar' => '3 مارس 2000',
        ]);

        $this->assertSame('2000-03-03', $data['date_naissance']);
        $this->assertSame('3 مارس 2000', $data['date_naissance_ar']);
    }
    public function test_arabic_birth_date_is_filled_from_latin_when_missing(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom'     => 'OMAR TAZI',
            'cin'            => 'CD456789',
            'date_naissance' => '1990-12-25',
        ]);

        $this->assertSame('1990-12-25', $data['date_naissance']);
        $this->assertSame('25 دجنبر 1990', $data['date_naissance_ar']);
    }

    public function test_read_nationality_is_preserved(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom'     => 'ANA SILVA',
            'cin'            => 'EE111222',
            'nationalite'    => 'PORTUGAISE',
        ]);

        $this->assertSame('PORTUGAISE', $data['nationalite']);
    }

    public function test_name_is_uppercased_and_whitespace_collapsed(): void
    {
        $data = $this->normalizer->normalize([
            'nom_prenom' => '  fatima   zahra  idrissi ',
            'cin'        => 'AB123456',
        ]);

        $this->assertSame('FATIMA ZAHRA IDRISSI', $data['nom_prenom']);
    }
}
