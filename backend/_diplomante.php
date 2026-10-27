<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "=== FILIERES : nombre_annees ===\n";
foreach (DB::select('select id, nom, nombre_annees, type_formation from filieres order by id') as $r) {
    $out .= sprintf("  id=%-4d %-24s nombre_annees=%-3s type=%s\n",
        $r->id, $r->nom, $r->nombre_annees ?? 'NULL', $r->type_formation ?? '-');
}

$out .= "\n=== ANNEES ACADEMIQUES ===\n";
foreach (DB::select('select id, label, is_current from annees_academiques order by id') as $r) {
    $out .= sprintf("  id=%-5d %-16s current=%d\n", $r->id, $r->label, $r->is_current);
}

$out .= "\n=== CANDIDATS 'annee diplomante' PAR ANNEE (logique du controleur) ===\n";

foreach (DB::select('select id, label from annees_academiques order by id') as $annee) {
    $rows = DB::select('
        select f.nom as filiere, f.nombre_annees, n.numero as niveau, count(e.id) as n
        from etudiants e
        join groupes g on g.id = e.groupe_id
        join niveaux n on n.id = g.niveau_id
        left join filieres f on f.id = n.filiere_id
        where e.annee_academique_id = ?
        group by f.nom, f.nombre_annees, n.numero
        order by f.nom, n.numero
    ', [$annee->id]);

    $total = 0;
    $lignes = [];
    foreach ($rows as $r) {
        $diplomante = ($r->nombre_annees !== null && (int) $r->niveau === (int) $r->nombre_annees);

        // On ne peut lire "diplomante" que si la filiere a un nombre_annees.
        $lignes[] = sprintf("    %-24s niv%-3s (sur %-3s ans) %-3d etu %s",
            $r->filiere ?? '?', $r->niveau, $r->nombre_annees ?? '?', $r->n,
            $diplomante ? '<-- ANNEE DIPLOMANTE' : '');
        if ($diplomante) {
            $total += $r->n;
        }
    }

    $out .= sprintf("  -- annee %s (id=%d) : %d candidats --\n", $annee->label, $annee->id, $total);
    foreach ($lignes as $l) {
        $out .= $l . "\n";
    }
    $out .= "\n";
}

file_put_contents(__DIR__ . '/_diplomante.txt', $out);
echo "written\n";
