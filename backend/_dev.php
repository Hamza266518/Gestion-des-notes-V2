<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// On lit la base du .env (celle que sert l'application), PAS la base de test.
$db = DB::connection()->getDatabaseName();

$out = "DB de l'application : $db\n\n";

$out .= "== annees_academiques ==\n";
foreach (DB::select('select id, label, is_current, is_archived from annees_academiques order by id') as $r) {
    $out .= sprintf("  id=%-5d %-16s current=%d archived=%d\n", $r->id, $r->label, $r->is_current, $r->is_archived);
}

$out .= "\n== etudiants par annee/niveau/filiere ==\n";
foreach (DB::select('
        select a.label as annee, f.nom as filiere, n.numero as niveau, g.id as gid, g.nom as gnom,
               count(e.id) as n
        from etudiants e
        join groupes g on g.id = e.groupe_id
        join niveaux n on n.id = g.niveau_id
        join annees_academiques a on a.id = e.annee_academique_id
        left join filieres f on f.id = n.filiere_id
        group by a.label, f.nom, n.numero, g.id, g.nom
        order by a.label, f.nom, n.numero
    ') as $r) {
    $out .= sprintf("  %-12s %-24s niv%-3s g%-6d %-10s %d etu\n",
        $r->annee, $r->filiere ?? '-', $r->niveau, $r->gid, $r->gnom, $r->n);
}

$out .= "\n== totaux ==\n";
$out .= "  etudiants : " . DB::table('etudiants')->count() . "\n";
$out .= "  notes     : " . DB::table('notes')->count() . "\n";
$out .= "  groupes   : " . DB::table('groupes')->count() . "\n";

file_put_contents(__DIR__ . '/_dev.txt', $out);
echo "written\n";
