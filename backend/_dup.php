<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Les 107 candidats "annee diplomante" : niveau == nombre_annees de la filiere.
$candidats = DB::select('
    select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.date_naissance, e.status,
           g.id as gid, g.nom as gnom, n.numero as niveau, f.nom as filiere, f.nombre_annees
    from etudiants e
    join groupes g on g.id = e.groupe_id
    join niveaux n on n.id = g.niveau_id
    join filieres f on f.id = n.filiere_id
    where e.annee_academique_id = 1
      and n.numero = f.nombre_annees
    order by f.nom, e.nom_prenom
');

$norm = function (?string $s): string {
    $s = preg_replace('/\s+/', ' ', trim(mb_strtoupper((string) $s)));
    return $s;
};

$out = "=== " . count($candidats) . " CANDIDATS 'ANNEE DIPLOMANTE' (2025/2026) ===\n\n";

// --- Doublons par NOM ---
$parNom = [];
foreach ($candidats as $c) {
    $parNom[$norm($c->nom_prenom)][] = $c;
}

$out .= "--- DOUBLONS PAR NOM ---\n";
$dn = 0;
foreach ($parNom as $nom => $liste) {
    if (count($liste) > 1) {
        $dn++;
        $out .= sprintf("  x%d  %s\n", count($liste), $nom);
        foreach ($liste as $c) {
            $out .= sprintf("        id=%-5d cin=%-12s num=%-12s %-20s niv%-3s\n",
                $c->id, $c->cin ?? '-', $c->numero_inscription ?? '-', $c->filiere, $c->niveau);
        }
    }
}
$out .= "  => $dn noms en doublon\n\n";

// --- Doublons par CIN ---
$parCin = [];
foreach ($candidats as $c) {
    $cin = $norm($c->cin);
    if ($cin !== '') {
        $parCin[$cin][] = $c;
    }
}

$out .= "--- DOUBLONS PAR CIN ---\n";
$dc = 0;
foreach ($parCin as $cin => $liste) {
    if (count($liste) > 1) {
        $dc++;
        $out .= sprintf("  x%d  CIN=%s\n", count($liste), $cin);
        foreach ($liste as $c) {
            $out .= sprintf("        id=%-5d %-30s num=%-12s %-20s niv%-3s\n",
                $c->id, $c->nom_prenom, $c->numero_inscription ?? '-', $c->filiere, $c->niveau);
        }
    }
}
$out .= "  => $dc CIN en doublon\n\n";

// --- Doublons par NUMERO ---
$parNum = [];
foreach ($candidats as $c) {
    $num = $norm($c->numero_inscription);
    if ($num !== '') {
        $parNum[$num][] = $c;
    }
}

$out .= "--- DOUBLONS PAR NUMERO D'INSCRIPTION ---\n";
$dnum = 0;
foreach ($parNum as $num => $liste) {
    if (count($liste) > 1) {
        $dnum++;
        $out .= sprintf("  x%d  num=%s\n", count($liste), $num);
        foreach ($liste as $c) {
            $out .= sprintf("        id=%-5d %-30s cin=%-12s %-20s niv%-3s\n",
                $c->id, $c->nom_prenom, $c->cin ?? '-', $c->filiere, $c->niveau);
        }
    }
}
$out .= "  => $dnum numeros en doublon\n\n";

// --- Detail Aide-Soignant (la filiere qui gonfle) ---
$out .= "--- DETAIL AIDE-SOIGNANT (72 attendus) ---\n";
$as = array_values(array_filter($candidats, fn ($c) => $c->filiere === 'Aide-Soignant'));
$out .= sprintf("  total AS niv1 : %d\n\n", count($as));

foreach ($as as $c) {
    $out .= sprintf("  id=%-5d %-30s cin=%-14s num=%-14s né=%s\n",
        $c->id, $c->nom_prenom, $c->cin ?? '-', $c->numero_inscription ?? '-', $c->date_naissance ?? '-');
}

file_put_contents(__DIR__ . '/_dup.txt', $out);
echo "written\n";
