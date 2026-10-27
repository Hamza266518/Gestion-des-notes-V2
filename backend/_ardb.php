<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$officiels = json_decode(file_get_contents(__DIR__ . '/_laureats_officiels.json'), true)['numeros'];
$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$offNorm = [];
foreach ($officiels as $o) { $offNorm[$norm($o)] = true; }

$out = "=== ARABE DES 76 LAUREATS EN BASE ===\n\n";

$rows = DB::select("
    select e.id, e.nom_prenom, e.nom_ar, e.cin, e.cin_ar, e.numero_inscription, e.numero_inscription_ar,
           e.date_naissance_ar, e.lieu_naissance_ar, e.nationalite, e.nationalite_ar
    from etudiants e
    order by e.id
");

$n = 0;
foreach ($rows as $e) {
    if (!isset($offNorm[$norm($e->numero_inscription)])) continue;
    $n++;
    $manque = [];
    foreach (['nom_ar','cin_ar','numero_inscription_ar','date_naissance_ar','lieu_naissance_ar','nationalite_ar'] as $f) {
        if (trim((string) $e->$f) === '') $manque[] = $f;
    }
    $out .= sprintf("%-10s id=%-5d %-30s\n", $e->numero_inscription, $e->id, $e->nom_prenom);
    $out .= sprintf("     nom_ar=%-30s cin_ar=%-14s num_ar=%-14s\n", $e->nom_ar ?? '-', $e->cin_ar ?? '-', $e->numero_inscription_ar ?? '-');
    $out .= sprintf("     nat_ar=%-16s lieu_ar=%-16s dn_ar=%s\n", $e->nationalite_ar ?? '-', $e->lieu_naissance_ar ?? '-', $e->date_naissance_ar ?? '-');
    if ($manque !== []) {
        $out .= "     MANQUE : " . implode(', ', $manque) . "\n";
    }
    $out .= "\n";
}

$out = "  total : $n / 76\n\n" . $out;

file_put_contents(__DIR__ . '/_ar_db.txt', $out);
echo "written $n\n";
