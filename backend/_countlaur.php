<?php

// Compte les laureats 2025/2026 a partir du PDF extrait.
$text = file_get_contents(__DIR__ . '/_laureats.txt');

// Un numero d'inscription ressemble a : 96AS26, 39 IP24, 10 IA25, 141AS26 ...
preg_match_all('/(\d{1,3})\s*([A-Z]{2})(\d{2}|[A-Z]{0,2}\d{0,2})/u', $text, $m, PREG_SET_ORDER);

$parFiliere = [];
$parPromo = [];
$total = 0;

foreach ($m as $x) {
    $num = trim($x[1]);
    $fil = $x[2];      // AS, IP, IA
    $suf = $x[3];
    $key = "$fil$suf";
    $parPromo[$key] = ($parPromo[$key] ?? 0) + 1;
    $parFiliere[$fil] = ($parFiliere[$fil] ?? 0) + 1;
    $total++;
}

echo "=== LAUREATS 2025/2026 (depuis LAUREATS_2025_2026_CIN_EXACT.pdf) ===\n\n";
echo "Par filiere :\n";
ksort($parFiliere);
foreach ($parFiliere as $f => $n) {
    echo sprintf("  %-4s %d\n", $f, $n);
}
echo "\nPar numero (filiere+promo) :\n";
ksort($parPromo);
foreach ($parPromo as $k => $n) {
    echo sprintf("  %-12s %d\n", $k, $n);
}
echo "\nTOTAL laureats : $total\n";

// Verification manuelle : lister tous les numeros trouves
preg_match_all('/\b\d{1,3}\s?(?:AS|IP|IA)\s?\d{0,2}\b/u', $text, $flat);
$nums = array_map(fn($s) => trim(preg_replace('/\s+/', ' ', $s)), $flat[0]);
echo "\nNumeros trouves (" . count($nums) . ") :\n";
foreach (array_chunk($nums, 10) as $chunk) {
    echo "  " . implode(', ', $chunk) . "\n";
}
