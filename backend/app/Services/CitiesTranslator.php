<?php

namespace App\Services;

/**
 * Moroccan cities in their everyday French spelling, mapped to the Arabic
 * place of birth shown on the diploma. Kept in sync with
 * frontend/src/utils/helpers.js (LIEU_FR_TO_AR): both sides must resolve the
 * same place names. Unknown places return '' so a diploma never prints the
 * French word on its Arabic side.
 */
class CitiesTranslator
{
    private const MAP = [
        'casablanca' => 'الدار البيضاء',
        'rabat' => 'الرباط',
        'marrakech' => 'مراكش',
        'fes' => 'فاس',
        'tanger' => 'طنجة',
        'agadir' => 'أكادير',
        'meknes' => 'مكناس',
        'oujda' => 'وجدة',
        'kenitra' => 'القنيطرة',
        'tetouan' => 'تطوان',
        'safi' => 'آسفي',
        'sale' => 'سلا',
        'beni mellal' => 'بني ملال',
        'nador' => 'الناظور',
        'el jadida' => 'الجديدة',
        'berkane' => 'بركان',
        'mohammedia' => 'المحمدية',
        'ksar el kebir' => 'القصر الكبير',
        'settat' => 'سطات',
        'berrechid' => 'برشيد',
        'benslimane' => 'بن سليمان',
        'khouribga' => 'خريبكة',
        'errachidia' => 'الرشيدية',
        'ouarzazate' => 'ورزازات',
        'guelmim' => 'كلميم',
        'taza' => 'تازة',
        'larache' => 'العرائش',
        'tiflet' => 'تيفلت',
        'khemisset' => 'الخميسات',
        'el hoceima' => 'الحسيمة',
        'al hoceima' => 'الحسيمة',
        'dakhla' => 'الداخلة',
        'laayoune' => 'العيون',
        'laayoune bira' => 'العيون',
        'essaouira' => 'الصويرة',
        'figuig' => 'فجيج',
        'sidi kacem' => 'سيدي قاسم',
        'sidi slimane' => 'سيدي سليمان',
        'taourirt' => 'تاوريرت',
        'jerada' => 'جرادة',
        'tan tan' => 'طانطان',
        'ifrane' => 'إفران',
        'chefchaouen' => 'شفشاون',
        'bouznika' => 'بوزنيقة',
        'temara' => 'تمارة',
        'skhirat' => 'الصخيرات',
        'youssoufia' => 'اليوسفية',
        'sidi bennour' => 'سيدي بنور',
        'oued zem' => 'واد زم',
        'ben guerir' => 'بن غيرير',
        'kelaa des sraghna' => 'قلعة السراغنة',
        'chichaoua' => 'شيشاوة',
        'sidi ifni' => 'سيدي إفني',
        'tarfaya' => 'طرفاية',
        'boujdour' => 'بوجدور',
        'saidia' => 'السعيدية',
        'martil' => 'مرتيل',
        'ouled teima' => 'أولاد تايمة',
        'taroudant' => 'تارودانت',
        'ait melloul' => 'أيت ملول',
        'inezgane' => 'انزكان',
        'tiznit' => 'تيزنيت',
        'zagora' => 'زاكورة',
        'tinghir' => 'تينغير',
        'souss' => 'سوس',
        'sefrou' => 'صفرو',
        'melilla' => 'مليلية',
    ];

    public function translate(?string $french): string
    {
        $key = $this->fold((string) $french);

        return $key === '' ? '' : (self::MAP[$key] ?? '');
    }

    private function fold(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ò' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $value = str_replace(['’', "'", '-', '_'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}