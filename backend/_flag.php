<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Liste officielle des laureats 2025/2026 (extraite du PDF).
$officiels = json_decode(file_get_contents(__DIR__ . '/_laureats_officiels.json'), true)['numeros'];

$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$officielsNorm = array_flip(array_map($norm, $officiels));

// Le pool "annee diplomante" tel que le voit l'application (niveau == nombre_annees).
$pool = DB::select('
    select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.date_naissance, e.status,
           e.groupe_id, g.nom as gnom, n.numero as niveau, f.nom as filiere, f.nombre_annees
    from etudiants e
    join groupes g on g.id = e.groupe_id
    join niveaux n on n.id = g.niveau_id
    join filieres f on f.id = n.filiere_id
    where e.annee_academique_id = 1
      and n.numero = f.nombre_annees
    order by f.nom, e.nom_prenom
');

$matches = [];
$nonMatches = [];

foreach ($pool as $e) {
    $num = $norm($e->numero_inscription);
    if ($num !== '' && isset($officielsNorm[$num])) {
        $matches[] = $e;
    } else {
        $nonMatches[] = $e;
    }
}

$out = "=== POOL 'ANNEE DIPLOMANTE' : " . count($pool) . " etudiants ===\n";
$out .= "   MATCH la liste officielle : " . count($matches) . "\n";
$out .= "   NE MATCH PAS             : " . count($nonMatches) . "\n\n";

$out .= "=== NE MATCHENT PAS LA LISTE OFFICIELLE (" . count($nonMatches) . ") ===\n\n";
foreach ($nonMatches as $e) {
    $out .= sprintf("  id=%-5d %-30s cin=%-14s num=%-14s %-22s niv%-3s né=%s\n",
        $e->id, $e->nom_prenom, $e->cin ?? '-', $e->numero_inscription ?? '-',
        $e->filiere, $e->niveau, $e->date_naissance ?? '-');
}

// Repartition par filiere des non-matches
$parFil = [];
foreach ($nonMatches as $e) {
    $parFil[$e->filiere] = ($parFil[$e->filiere] ?? 0) + 1;
}
$out .= "\n  Par filiere :\n";
foreach ($parFil as $f => $n) {
    $out .= sprintf("    %-24s %d\n", $f, $n);
}

// Ceux qui matchent, par filiere
$out .= "\n=== MATCHENT LA LISTE OFFICIELLE (" . count($matches) . ") ===\n";
$parFil2 = [];
foreach ($matches as $e) {
    $parFil2[$e->filiere] = ($parFil2[$e->filiere] ?? 0) + 1;
}
foreach ($parFil2 as $f => $n) {
    $out .= sprintf("    %-24s %d\n", $f, $n);
}

// Notes des non-matches
if ($nonMatches !== []) {
    $ids = array_map(fn ($e) => $e->id, $nonMatches);
    $out .= "\n  Notes des non-matches : " . DB::table('notes')->whereIn('etudiant_id', $ids)->count() . "\n";
}

// Les officiels introuvables dans le pool
$poolNums = array_flip(array_map(fn ($e) => $norm($e->numero_inscription), $pool));
$absents = [];
foreach ($officiels as $num) {
    if (!isset($poolNums[$norm($num)])) {
        $absents[] = $num;
    }
}
$out .= "\n  Laureats officiels ABSENTS du pool : " . count($absents) . "\n";
foreach ($absents as $a) {
    $out .= "     - $a\n";
}

file_put_contents(__DIR__ . '/_flag.txt', $out);
echo "written\n";
