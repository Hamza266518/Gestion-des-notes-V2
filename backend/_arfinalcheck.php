<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$pdf = json_decode(file_get_contents(__DIR__ . '/_ar_names2.json'), true);
$byNum = [];
foreach ($pdf as $p) {
    $byNum[preg_replace('/\s+/', '', $p['num'])] = $p['nom_ar'];
}

$normAr = function (string $s): string {
    $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', $s);
    $s = preg_replace('/\s+/u', '', $s);
    $s = str_replace(['أ','إ','آ','ٱ'], 'ا', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = str_replace(['ة'], 'ه', $s);
    return $s;
};

$out = "=== ARABE, RECHECK FINAL ===\n\n";
$ok = 0;
$ecarts = [];

foreach (DB::table('etudiants')->get() as $e) {
    $key = preg_replace('/\s+/', '', (string) $e->numero_inscription);
    if (!isset($byNum[$key])) continue;
    $pdfAr = $byNum[$key];
    $dbAr = (string) $e->nom_ar;

    if ($normAr($dbAr) === $normAr($pdfAr)) {
        $ok++;
    } else {
        $ecarts[] = ['num' => $e->numero_inscription, 'id' => $e->id, 'fr' => $e->nom_prenom, 'pdf' => $pdfAr, 'db' => $dbAr];
    }
}

$out .= sprintf("  identiques : %d / 76\n", $ok);
$out .= sprintf("  ecarts     : %d\n\n", count($ecarts));

foreach ($ecarts as $x) {
    $out .= sprintf("  %-10s id=%-5d %-30s\n", $x['num'], $x['id'], $x['fr']);
    $out .= "        PDF : " . $x['pdf'] . "\n";
    $out .= "        BASE: " . $x['db'] . "\n";
    $pdfHex = array_map(fn ($c) => sprintf('%04X', mb_ord($c, 'UTF-8')), preg_split('//u', $x['pdf'], -1, PREG_SPLIT_NO_EMPTY));
    $dbHex  = array_map(fn ($c) => sprintf('%04X', mb_ord($c, 'UTF-8')), preg_split('//u', $x['db'], -1, PREG_SPLIT_NO_EMPTY));
    $out .= "        PDF  (" . count($pdfHex) . ") : " . implode(' ', $pdfHex) . "\n";
    $out .= "        BASE (" . count($dbHex) . ") : " . implode(' ', $dbHex) . "\n\n";
}

file_put_contents(__DIR__ . '/_ar_recheck2.txt', $out);
echo "written\n";
