<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "=== LES 2 ECARTS ARABE A TRANCHER ===\n\n";

// 142 AS26 - Widad ALLA (id 588)
$e1 = DB::table('etudiants')->where('id', 588)->first();
$out .= "--- 142 AS26 : {$e1->nom_prenom} (id=588) ---\n";
$out .= "  BASE  : {$e1->nom_ar}\n";
$out .= "  PDF   : وداد عا        (rendu brut du document)\n";
$out .= "  Nom francais : ALLA\n";
$out .= "  -> probablement : وداد علام (ALLA = علاّم)\n\n";

// 56 IP24 - BEN ALLAH CHAHRAZAD (id 656)
$e2 = DB::table('etudiants')->where('id', 656)->first();
$out .= "--- 56 IP24 : {$e2->nom_prenom} (id=656) ---\n";
$out .= "  BASE  : {$e2->nom_ar}\n";
$out .= "  PDF   : شهرزاد بنلله   (rendu brut du document)\n";
$out .= "  Nom francais : BEN ALLAH\n";
$out .= "  -> base l'ecrit bien : بن الله\n\n";

// En hexa pour etre sur des caracteres
$out .= "--- CODES ---\n";
foreach ([['base 142', $e1->nom_ar], ['base 56', $e2->nom_ar]] as [$k, $v]) {
    $hex = array_map(fn ($c) => sprintf('%04X', mb_ord($c, 'UTF-8')), preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY));
    $out .= "  $k : $v   [" . implode(' ', $hex) . "]\n";
}

file_put_contents(__DIR__ . '/_ar_two.txt', $out);
echo "written\n";
