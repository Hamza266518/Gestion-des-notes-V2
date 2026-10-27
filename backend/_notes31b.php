<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$ids31 = [680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,698,699,
          700,701,702,703,704,705,706,707,708,709,710];

$out = "=== NOTES PAR ID, POUR LES 31 ===\n\n";
foreach ($ids31 as $id) {
    $e = DB::table('etudiants')->where('id', $id)->first();
    if (!$e) { $out .= "  id=$id INTROUVABLE\n"; continue; }
    $n = DB::table('notes')->where('etudiant_id', $id)->count();
    $out .= sprintf("  id=%-5d %-30s num=%-14s cin=%-12s notes=%d\n",
        $id, $e->nom_prenom, $e->numero_inscription ?? '-', $e->cin ?? '-', $n);
}

// Autres tables qui referencent l'etudiant
$out .= "\n=== AUTRES REFERENCES ===\n";
foreach (['diplomes','stage_evaluations','stage_notes','examens'] as $t) {
    $cols = DB::select("show columns from $t");
    $has = false;
    foreach ($cols as $c) {
        if ($c->Field === 'etudiant_id') { $has = true; }
    }
    if (!$has) { $out .= "  $t : pas de etudiant_id\n"; continue; }
    $n = DB::table($t)->whereIn('etudiant_id', $ids31)->count();
    $out .= sprintf("  %-20s %d lignes\n", $t, $n);
}

file_put_contents(__DIR__ . '/_notes31b.txt', $out);
echo "written\n";
