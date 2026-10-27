<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$id = 667; // 75 IP24 - MEDDAH ZAINEB

$avant = DB::table('etudiants')->where('id', $id)->first();

$out = "=== CORRECTION 75 IP24 (id=$id) ===\n\n";
$out .= "AVANT :\n";
$out .= "  date_naissance   = " . ($avant->date_naissance ?? '(vide)') . "\n";
$out .= "  date_inscription = " . ($avant->date_inscription ?? '(vide)') . "\n\n";

// Le PDF donne une seule date, 08/05/2001, en position "date d'inscription".
// On la deplace : naissance laissee vide, inscription = 2001-05-08.
DB::table('etudiants')->where('id', $id)->update([
    'date_naissance'   => null,
    'date_inscription' => '2001-05-08',
    'updated_at'       => now(),
]);

$apres = DB::table('etudiants')->where('id', $id)->first();

$out .= "APRES :\n";
$out .= "  date_naissance   = " . ($apres->date_naissance ?? '(vide)') . "\n";
$out .= "  date_inscription = " . ($apres->date_inscription ?? '(vide)') . "\n";
$out .= "\n  (PDF : 08/05/2001 en date d'inscription)\n";

file_put_contents(__DIR__ . '/_fix667.txt', $out);
echo "written\n";
