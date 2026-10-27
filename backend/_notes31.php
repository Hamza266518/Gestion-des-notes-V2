<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$ids31 = [680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,698,699,
          700,701,702,703,704,705,706,707,708,709,710];

$in31 = implode(',', $ids31);

$out = "=== LES NOTES DES 31 ROWS A SUPPRIMER ===\n\n";

$rows = DB::select("
    select n.id, n.etudiant_id, n.valeur, n.est_absent, n.controle_id,
           c.nom as controle, c.numero, c.date, c.note_max,
           u.nom as unite, e.nom_prenom
    from notes n
    left join controles c on c.id = n.controle_id
    left join unites u on u.id = c.unite_id
    left join etudiants e on e.id = n.etudiant_id
    where n.etudiant_id in ($in31)
    order by e.nom_prenom, u.nom, c.numero
");

$out .= "  total notes : " . count($rows) . "\n\n";

$parU = [];
foreach ($rows as $r) {
    $parU[$r->unite ?? '(sans unite)'] = ($parU[$r->unite ?? '(sans unite)'] ?? 0) + 1;
}
$out .= "  -- par unite --\n";
arsort($parU);
foreach ($parU as $u => $n) {
    $out .= sprintf("     %-40s %d\n", $u, $n);
}

$parE = [];
foreach ($rows as $r) {
    $parE[$r->nom_prenom] = ($parE[$r->nom_prenom] ?? 0) + 1;
}
$out .= "\n  -- par etudiant --\n";
arsort($parE);
foreach ($parE as $nom => $n) {
    $out .= sprintf("     %-34s %d\n", $nom, $n);
}

$absent = count(array_filter($rows, fn ($r) => $r->est_absent == 1));
$out .= "\n  dont absents : $absent\n";
$ctrls = array_unique(array_map(fn ($r) => $r->controle_id, $rows));
$out .= "  controles concernes : " . count($ctrls) . "\n";

// Notes des 76 laureats
$officiels = json_decode(file_get_contents(__DIR__ . '/_laureats_officiels.json'), true)['numeros'];
$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$offNorm = [];
foreach ($officiels as $o) {
    $offNorm[$norm($o)] = true;
}
$laurIds = [];
foreach (DB::table('etudiants')->get() as $e) {
    if (isset($offNorm[$norm($e->numero_inscription)])) {
        $laurIds[] = $e->id;
    }
}
$inL = implode(',', $laurIds);

$out .= "\n=== NOTES DES 76 LAUREATS ===\n";
foreach (DB::select("
    select u.nom as unite, count(n.id) as n
    from notes n
    left join controles c on c.id = n.controle_id
    left join unites u on u.id = c.unite_id
    where n.etudiant_id in ($inL)
    group by u.nom
    order by n desc
") as $r) {
    $out .= sprintf("     %-40s %d\n", $r->unite ?? '(sans)', $r->n);
}

// echantillon
$out .= "\n=== ECHANTILLON (12 notes des 31) ===\n";
foreach (array_slice($rows, 0, 12) as $r) {
    $out .= sprintf("     %-28s %-30s %-16s val=%-6s abs=%s\n",
        $r->nom_prenom, $r->unite ?? '-', $r->controle ?? '-', $r->valeur ?? '-', $r->est_absent);
}

// Est-ce que ces 31 partagent leurs controles avec de vrais etudiants ?
$out .= "\n=== LES CONTROLES DES 31 SONT-ILS PARTAGES AVEC D'AUTRES ETUDIANTS ? ===\n";
$shared = 0;
$only31 = 0;
foreach ($ctrls as $cid) {
    $autres = DB::table('notes')->where('controle_id', $cid)->whereNotIn('etudiant_id', $ids31)->count();
    if ($autres > 0) {
        $shared++;
    } else {
        $only31++;
    }
}
$out .= "  controles partages avec d'autres etudiants : $shared\n";
$out .= "  controles uniquement lies aux 31         : $only31\n";

file_put_contents(__DIR__ . '/_notes31.txt', $out);
echo "written\n";
