<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$pdf = json_decode(file_get_contents(__DIR__ . '/_pdf_full.json'), true);
$byNum = [];
foreach ($pdf as $p) {
    $byNum[preg_replace('/\s+/', '', $p['num'])] = $p;
}

$normAr = function (?string $s): string {
    $s = preg_replace('/[\x{0640}\x{064B}-\x{0652}\x{0670}]/u', '', (string) $s);
    $s = preg_replace('/\s+/u', '', $s);
    $s = str_replace(['أ','إ','آ','ٱ'], 'ا', $s);
    $s = str_replace(['ى'], 'ي', $s);
    $s = str_replace(['ة'], 'ه', $s);
    return $s;
};
$normTxt = fn (?string $s) => preg_replace('/\s+/u', '', mb_strtoupper(trim((string) $s)));
$toYmd = function (?string $d): ?string {
    if (!$d) return null;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($d), $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    return trim($d);
};

$out = "=== VERIFICATION COMPLETE DES 76 LAUREATS (tous champs) ===\n\n";

$stats = [];
$ecarts = [];

foreach (DB::table('etudiants')->get() as $e) {
    $key = $normTxt($e->numero_inscription);
    if (!isset($byNum[$key])) continue;
    $p = $byNum[$key];

    $checks = [
        'CIN'              => [$normTxt($e->cin), $normTxt($p['cin'])],
        'date_naissance'   => [$e->date_naissance ? substr($e->date_naissance, 0, 10) : null, $toYmd($p['dnaiss'])],
        'date_inscription' => [$e->date_inscription ? substr($e->date_inscription, 0, 10) : null, $toYmd($p['dinsc'])],
        'nom_ar'           => [$normAr($e->nom_ar), $normAr($p['nom_ar'])],
        'lieu_naissance_ar'=> [$normAr($e->lieu_naissance_ar), $normAr($p['lieu_ar'])],
    ];

    foreach ($checks as $field => [$db, $pd]) {
        $stats[$field]['total'] = ($stats[$field]['total'] ?? 0) + 1;
        // Le PDF peut etre vide pour un champ : on ne compte pas comme erreur si base pleine
        if ($pd === '' || $pd === null) {
            $stats[$field]['pdf_vide'] = ($stats[$field]['pdf_vide'] ?? 0) + 1;
            continue;
        }
        if ($db === $pd) {
            $stats[$field]['ok'] = ($stats[$field]['ok'] ?? 0) + 1;
        } else {
            $stats[$field]['ko'] = ($stats[$field]['ko'] ?? 0) + 1;
            $ecarts[] = [
                'num' => $e->numero_inscription, 'id' => $e->id, 'fr' => $e->nom_prenom,
                'field' => $field, 'db' => $db, 'pdf' => $pd,
            ];
        }
    }
}

$out .= "--- TAUX PAR CHAMP ---\n";
foreach ($stats as $f => $s) {
    $out .= sprintf("  %-20s ok=%-3d ko=%-3d pdf_vide=%-3d\n", $f, $s['ok'] ?? 0, $s['ko'] ?? 0, $s['pdf_vide'] ?? 0);
}

$out .= "\n--- ECARTS (" . count($ecarts) . ") ---\n\n";
foreach ($ecarts as $x) {
    $out .= sprintf("  %-10s id=%-5d %-28s [%s]\n", $x['num'], $x['id'], $x['fr'], $x['field']);
    $out .= "        BASE: " . ($x['db'] ?? '(vide)') . "\n";
    $out .= "        PDF : " . ($x['pdf'] ?? '(vide)') . "\n";
}

file_put_contents(__DIR__ . '/_verif_all.txt', $out);
echo "written\n";
