<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "";

// Tous les etudiants, avec leurs champs de remplissage.
$db = DB::select('
    select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.date_naissance,
           e.lieu_naissance, e.nationalite, e.date_inscription,
           g.nom as gnom, n.numero as niveau, f.nom as filiere
    from etudiants e
    left join groupes g on g.id = e.groupe_id
    left join niveaux n on n.id = g.niveau_id
    left join filieres f on f.id = n.filiere_id
    order by f.nom, n.numero, e.nom_prenom
');

// Combien d'etudiants ont des infos "reelles" (date naissance + lieu + date inscription)
$complets = 0;
$lignesCompletes = [];
$lignesVides = [];

foreach ($db as $e) {
    $ok = $e->date_naissance && trim((string) $e->lieu_naissance) !== '' && $e->date_inscription;
    if ($ok) {
        $complets++;
        $lignesCompletes[] = $e;
    } else {
        $lignesVides[] = $e;
    }
}

$out .= "=== ETAT DES FICHES ETUDIANTS ===\n\n";
$out .= sprintf("  fiches completes (naissance + lieu + date insc): %d\n", $complets);
$out .= sprintf("  fiches incompletes                           : %d\n", count($lignesVides));
$out .= sprintf("  total                                       : %d\n\n", count($db));

// Qui sont les complets ?
$parFil = [];
foreach ($lignesCompletes as $e) {
    $k = ($e->filiere ?? '?') . ' niv' . ($e->niveau ?? '?');
    $parFil[$k] = ($parFil[$k] ?? 0) + 1;
}
$out .= "  -- fiches COMPLETES par filiere/niveau --\n";
ksort($parFil);
foreach ($parFil as $k => $n) {
    $out .= sprintf("     %-34s %d\n", $k, $n);
}

// Qui sont les incomplets ?
$parFil2 = [];
foreach ($lignesVides as $e) {
    $k = ($e->filiere ?? '?') . ' niv' . ($e->niveau ?? '?');
    $parFil2[$k] = ($parFil2[$k] ?? 0) + 1;
}
$out .= "\n  -- fiches INCOMPLETES par filiere/niveau --\n";
ksort($parFil2);
foreach ($parFil2 as $k => $n) {
    $out .= sprintf("     %-34s %d\n", $k, $n);
}

// Les 41 laureats AS : sont-ils complets ?
$officiels = json_decode(file_get_contents(__DIR__ . '/_laureats_officiels.json'), true)['numeros'];
$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$offNorm = [];
foreach ($officiels as $o) {
    $offNorm[$norm($o)] = true;
}

$laurComplets = 0;
$laurIncomplets = 0;
$laurDetail = [];
foreach ($db as $e) {
    if (!isset($offNorm[$norm($e->numero_inscription)])) {
        continue;
    }
    $ok = $e->date_naissance && trim((string) $e->lieu_naissance) !== '' && $e->date_inscription;
    if ($ok) {
        $laurComplets++;
    } else {
        $laurIncomplets++;
    }
    $laurDetail[] = [$e, $ok];
}

$out .= "\n=== LES 76 LAUREATS OFFICIELS ===\n";
$out .= sprintf("  fiches completes   : %d\n", $laurComplets);
$out .= sprintf("  fiches incompletes : %d\n", $laurIncomplets);

// Combien de notes ont les 31 vs les 76 ?
$ids31 = [680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,698,699,
          700,701,702,703,704,705,706,707,708,709,710];
$idsLaureats = [];
foreach ($db as $e) {
    if (isset($offNorm[$norm($e->numero_inscription)])) {
        $idsLaureats[] = $e->id;
    }
}

$out .= "\n=== NOTES ===\n";
$out .= sprintf("  notes des 31   : %d\n", DB::table('notes')->whereIn('etudiant_id', $ids31)->count());
$out .= sprintf("  notes des 76   : %d\n", DB::table('notes')->whereIn('etudiant_id', $idsLaureats)->count());

// Les 41 vrais AS : leurs numeros
$out .= "\n=== LES 41 LAUREATS AS (numeros) ===\n";
$asLaur = array_filter($db, fn ($e) => isset($offNorm[$norm($e->numero_inscription)]) && $e->filiere === 'Aide-Soignant');
$out .= "  " . count($asLaur) . " trouves\n";
foreach ($asLaur as $e) {
    $ok = $e->date_naissance && trim((string) $e->lieu_naissance) !== '' && $e->date_inscription;
    $out .= sprintf("     id=%-5d %-30s num=%-12s naiss=%-12s lieu=%-16s insc=%-12s %s\n",
        $e->id, $e->nom_prenom, $e->numero_inscription,
        $e->date_naissance ?? '-', $e->lieu_naissance ?? '-', $e->date_inscription ?? '-',
        $ok ? 'COMPLET' : 'incomplet');
}

file_put_contents(__DIR__ . '/_audit2.txt', $out);
echo "written\n";
