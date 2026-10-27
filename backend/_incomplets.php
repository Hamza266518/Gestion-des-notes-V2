<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::select('
    select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.date_naissance,
           e.lieu_naissance, e.date_inscription, e.cin_ar, e.nom_ar,
           g.nom as gnom, n.numero as niveau, f.nom as filiere
    from etudiants e
    left join groupes g on g.id = e.groupe_id
    left join niveaux n on n.id = g.niveau_id
    left join filieres f on f.id = n.filiere_id
    order by f.nom, n.numero, e.nom_prenom
');

$out = "=== LES 54 SANS DONNEES COMPLETES ===\n\n";

$par = [];
$liste = [];
foreach ($rows as $e) {
    $complet = $e->date_naissance && trim((string) $e->lieu_naissance) !== '' && $e->date_inscription && trim((string) $e->cin_ar) !== '';
    if ($complet) continue;
    $k = ($e->filiere ?? '?') . ' niv' . ($e->niveau ?? '?');
    $par[$k] = ($par[$k] ?? 0) + 1;
    $liste[] = $e;
}

$out .= "  total incomplets : " . count($liste) . "\n\n";
$out .= "  -- par filiere/niveau --\n";
ksort($par);
foreach ($par as $k => $n) {
    $out .= sprintf("     %-34s %d\n", $k, $n);
}

$out .= "\n  -- detail --\n";
foreach ($liste as $e) {
    $out .= sprintf("     id=%-5d %-30s num=%-14s cin=%-14s %-22s niv%-3s naiss=%-11s lieu=%-10s insc=%s\n",
        $e->id, $e->nom_prenom, $e->numero_inscription ?? '-', $e->cin ?? '-',
        $e->filiere ?? '-', $e->niveau ?? '-',
        $e->date_naissance ?? '-', $e->lieu_naissance ?? '-', $e->date_inscription ?? '-');
}

file_put_contents(__DIR__ . '/_incomplets.txt', $out);
echo "written " . count($liste) . "\n";
