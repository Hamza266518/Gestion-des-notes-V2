<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "=== CONTROLE GENERAL DES 130 ETUDIANTS ===\n\n";

$rows = DB::select('
    select e.id, e.nom_prenom, e.nom_ar, e.cin, e.cin_ar, e.numero_inscription, e.numero_inscription_ar,
           e.date_naissance, e.date_naissance_ar, e.lieu_naissance, e.lieu_naissance_ar,
           e.nationalite, e.nationalite_ar, e.date_inscription, e.date_inscription_ar,
           g.nom as gnom, n.numero as niveau, f.nom as filiere, a.label as annee
    from etudiants e
    left join groupes g on g.id = e.groupe_id
    left join niveaux n on n.id = g.niveau_id
    left join filieres f on f.id = n.filiere_id
    left join annees_academiques a on a.id = e.annee_academique_id
    order by f.nom, n.numero, e.nom_prenom
');

$out .= sprintf("  total etudiants : %d\n\n", count($rows));

// 1) Champs vides
$vide = [];
foreach ($rows as $e) {
    foreach (['nom_prenom','nom_ar','cin','cin_ar','numero_inscription','date_naissance',
              'lieu_naissance','nationalite','date_inscription'] as $f) {
        if (trim((string) $e->$f) === '') {
            $vide[$f][] = $e;
        }
    }
}
$out .= "--- CHAMPS VIDES (champ principal) ---\n";
foreach (['nom_prenom','nom_ar','cin','cin_ar','numero_inscription','date_naissance','lieu_naissance','nationalite','date_inscription'] as $f) {
    $n = count($vide[$f] ?? []);
    $out .= sprintf("  %-22s %d\n", $f, $n);
}

// 2) Doublons CIN
$out .= "\n--- DOUBLONS CIN ---\n";
$byCin = [];
foreach ($rows as $e) {
    $c = preg_replace('/\s+/', '', mb_strtoupper((string) $e->cin));
    if ($c !== '') $byCin[$c][] = $e;
}
$dc = 0;
foreach ($byCin as $c => $ls) {
    if (count($ls) > 1) {
        $dc++;
        $out .= sprintf("  CIN %s x%d : ", $c, count($ls));
        $out .= implode(' | ', array_map(fn ($x) => "#{$x->id} {$x->nom_prenom}", $ls));
        $out .= "\n";
    }
}
$out .= "  => $dc doublons\n";

// 3) Doublons numero inscription
$out .= "\n--- DOUBLONS NUMERO INSCRIPTION ---\n";
$byNum = [];
foreach ($rows as $e) {
    $c = preg_replace('/\s+/', '', mb_strtoupper((string) $e->numero_inscription));
    if ($c !== '') $byNum[$c][] = $e;
}
$dn = 0;
foreach ($byNum as $c => $ls) {
    if (count($ls) > 1) {
        $dn++;
        $out .= sprintf("  NUM %s x%d : ", $c, count($ls));
        $out .= implode(' | ', array_map(fn ($x) => "#{$x->id} {$x->nom_prenom} ({$x->filiere} niv{$x->niveau})", $ls));
        $out .= "\n";
    }
}
$out .= "  => $dn doublons\n";

// 4) Doublons nom_prenom
$out .= "\n--- DOUBLONS NOM ---\n";
$byNom = [];
foreach ($rows as $e) {
    $c = preg_replace('/[^A-Z ]/', '', preg_replace('/\s+/', ' ', str_replace(['-', "'"], ' ', mb_strtoupper((string) $e->nom_prenom))));
    if ($c !== '') $byNom[$c][] = $e;
}
$dnm = 0;
foreach ($byNom as $c => $ls) {
    if (count($ls) > 1) {
        $dnm++;
        $out .= sprintf("  NOM %s x%d : ", $c, count($ls));
        $out .= implode(' | ', array_map(fn ($x) => "#{$x->id} cin={$x->cin} ({$x->filiere})", $ls));
        $out .= "\n";
    }
}
$out .= "  => $dnm doublons\n";

// 5) Le CIN arabe est-il coherent avec le CIN latin ? (souvent identique)
$out .= "\n--- CIN arabe vs CIN latin (resume) ---\n";
$same = $diff = $vid = 0;
foreach ($rows as $e) {
    if (trim((string) $e->cin_ar) === '') { $vid++; continue; }
    if (preg_replace('/\s+/', '', $e->cin_ar) === preg_replace('/\s+/', '', (string) $e->cin)) $same++;
    else $diff++;
}
$out .= "  identiques=$same differents=$diff vides=$vid\n";

file_put_contents(__DIR__ . '/_ctrl_general.txt', $out);
echo "written\n";
