<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Les noms arabes extraits du PDF, dans l'ordre des lignes (cf _arpdf_lines.txt).
// On les associe aux numeros d'inscription dans l'ordre du document.
$pdfArabes = [
    // page 1 (41 AS)
    '96 AS26'  => 'حمزة العاشي',
    '97 AS26'  => 'أنس العوني',
    '99 AS26'  => 'أسامة الوزاني الشاهدي',
    '100 AS26' => 'وليد الماحي',
    '101 AS26' => 'عبد الغفور حجي',
    '102 AS26' => 'خولة الدخيسي',
    '103 AS26' => 'سليمة الرحماني',
    '105 AS26' => 'دعاء موزوري',
    '106 AS26' => 'سكينة اليوبي',
    '109 AS26' => 'هناء مهرز',
    '110 AS26' => 'بثينة أمير',
    '111 AS26' => 'إكرام حبجاوي',
    '112 AS26' => 'بسمة مختاري',
    '113 AS26' => 'أمل زمزم',
    '114 AS26' => 'نهيلة درفوف',
    '115 AS26' => 'زينب دوزي',
    '116 AS26' => 'أسماء أرغال',
    '117 AS26' => 'إيمان العمراوي',
    '119 AS26' => 'وصال إيشاوي',
    '121 AS26' => 'فاطمة الزهراء جلولي',
    '122 AS26' => 'منال بشيري',
    '123 AS26' => 'صباح البركاني',
    '124 AS26' => 'هاجر ملوكي',
    '125 AS26' => 'أميمة الدكاكي',
    '126 AS26' => 'هدى حاجي',
    '127 AS26' => 'سكينة بارويكي',
    '128 AS26' => 'ضحى بكاوي',
    '129 AS26' => 'يسرى الرحماني',
    '130 AS26' => 'رانيا الزاوي',
    '131 AS26' => 'ياسمين بختي',
    '132 AS26' => 'مريم مزياني',
    '133 AS26' => 'مريم قدوري',
    '135 AS26' => 'صابرين أوفقير',
    '136 AS26' => 'سهام بلخير',
    '137 AS26' => 'فاطمة الزهراء معروف',
    '138 AS26' => 'فاطمة الزهراء بولرحشا',
    '139 AS26' => 'دنيا لحمر',
    '141 AS26' => 'منال زريوح',
    '142 AS26' => 'وداد علام',
    '143 AS26' => 'أسامة تسولي',
    '144 AS26' => 'شهرة يوسفي',
    // page 3 (29 IP)
    '39 IP24'  => 'نبيل حاجي',
    '40 IP24'  => 'حياة حدو',
    '41 IP24'  => 'مريم العيساوي',
    '42 IP24'  => 'أميمة مرقوم',
    '43 IP24'  => 'منى السعيدي',
    '44 IP24'  => 'ابتسام بوجنان',
    '45 IP24'  => 'مروى قدوري',
    '46 IP24'  => 'أفراء العيساوي',
    '47 IP24'  => 'شهرزاد رحماني',
    '48 IP24'  => 'هجر برياح',
    '49 IP24'  => 'هاجر بالي',
    '50 IP24'  => 'فايزة الشالوي',
    '51 IP24'  => 'ناسي وئام',
    '52 IP24'  => 'آية برحاب',
    '53 IP24'  => 'سلمى فارسي',
    '54 IP24'  => 'إيمان معمري',
    '55 IP24'  => 'سلمى جلولي',
    '56 IP24'  => 'شهرزاد بن لله',
    '57 IP24'  => 'كوثر طاهري',
    '59 IP24'  => 'فاتن مهداوي',
    '60 IP24'  => 'سميرة عمارة',
    '61 IP24'  => 'بوطايب صفاء',
    '62 IP24'  => 'هند محروق',
    '63 IP24'  => 'شحبون هديل',
    '65 IP24'  => 'خولة القادري بوتشيش',
    '68 IP24'  => 'رميساء هيشور',
    '73 IP24'  => 'عال الغماري',
    '74 IP24'  => 'ضحى الحدادي',
    '75 IP24'  => 'زينب مداح',
    // page 4 (6 IA)
    '10 IA25' => 'شرف الطاهري',
    '11 IA25' => 'صابرينا عالوي',
    '12 IA25' => 'دعاء حديدوان',
    '13 IA25' => 'خديجة أقالش',
    '14 IA25' => 'دعاء الرحياتي',
    '15 IA25' => 'كوثر هامل',
];

// Normalisation arabe : retirer tatweel + diacritiques + espaces, unifier alef/ya/ta
$normAr = function (string $s): string {
    $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $s);
    $s = preg_replace('/\s+/u', '', $s);
    $s = str_replace(['أ','إ','آ','ٱ'], 'ا', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = str_replace(['ة'], 'ه', $s);
    return $s;
};

$out = "=== ARABE : PDF vs BASE (les 76) ===\n\n";

$ecarts = [];
$ok = 0;
$manquants = 0;

foreach ($pdfArabes as $num => $arPdf) {
    $e = DB::table('etudiants')->where('numero_inscription', $num)->first();
    if (!$e) {
        $out .= "  ? $num introuvable en base\n";
        continue;
    }
    $dbAr = (string) $e->nom_ar;
    if (trim($dbAr) === '') {
        $manquants++;
        $ecarts[] = ['num' => $num, 'id' => $e->id, 'fr' => $e->nom_prenom, 'pdf' => $arPdf, 'db' => '(vide)', 'type' => 'VIDE'];
        continue;
    }
    if ($normAr($dbAr) === $normAr($arPdf)) {
        $ok++;
    } else {
        $ecarts[] = ['num' => $num, 'id' => $e->id, 'fr' => $e->nom_prenom, 'pdf' => $arPdf, 'db' => $dbAr, 'type' => 'DIFFERENT'];
    }
}

$out .= sprintf("  identiques      : %d / 76\n", $ok);
$out .= sprintf("  avec ecart      : %d\n", count($ecarts));
$out .= sprintf("  dont vides      : %d\n\n", $manquants);

if ($ecarts !== []) {
    $out .= "=== DETAIL DES ECARTS ARABE ===\n\n";
    foreach ($ecarts as $x) {
        $out .= sprintf("  %-10s id=%-5d %-28s\n", $x['num'], $x['id'], $x['fr']);
        $out .= sprintf("        PDF : %s\n", $x['pdf']);
        $out .= sprintf("        BASE: %s\n\n", $x['db']);
    }
}

file_put_contents(__DIR__ . '/_ar_diff.txt', $out);
echo "written\n";
