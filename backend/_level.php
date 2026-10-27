<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "DB de l'application : " . DB::connection()->getDatabaseName() . "\n\n";

// Tous les niveaux (numéro + filière) pour savoir quelles années existent par filière.
$out .= "== NIVEAUX existants (par filiere) ==\n";
foreach (DB::select('
        select n.id, n.numero, f.nom as filiere,
               (select count(*) from groupes g where g.niveau_id = n.id) as nb_groupes
        from niveaux n left join filieres f on f.id = n.filiere_id
        order by f.nom, n.numero
    ') as $r) {
    $out .= sprintf("  niveau %-5d numero=%-3s %-24s (%d groupes)\n",
        $r->id, $r->numero ?? '-', $r->filiere ?? '-', $r->nb_groupes);
}

// Effectifs par filiere + niveau + groupe + promotion.
$out .= "\n== ETUDIANTS (filiere / niveau / promo) ==\n";
foreach (DB::select('
        select f.nom as filiere, n.numero as niveau, g.id as gid, g.nom as gnom, g.promotion,
               count(e.id) as n
        from etudiants e
        join groupes g on g.id = e.groupe_id
        join niveaux n on n.id = g.niveau_id
        left join filieres f on f.id = n.filiere_id
        group by f.nom, n.numero, g.id, g.nom, g.promotion
        order by f.nom, n.numero
    ') as $r) {
    $out .= sprintf("  %-24s niv%-3s g%-5d %-10s promo=%-10s %d etu\n",
        $r->filiere ?? '-', $r->niveau, $r->gid, $r->gnom, $r->promotion ?? '-', $r->n);
}

// Les eleves du dernier niveau par filiere = candidats au diplome.
$out .= "\n== DERNIER niveau par filiere (= diplomes potentiels) ==\n";
foreach (DB::select('
        select f.nom as filiere, max(n.numero) as dernier
        from niveaux n left join filieres f on f.id = n.filiere_id
        where f.nom is not null
        group by f.nom
    ') as $r) {
    $cnt = DB::select('
        select count(*) as c
        from etudiants e
        join groupes g on g.id = e.groupe_id
        join niveaux n on n.id = g.niveau_id
        join filieres f on f.id = n.filiere_id
        where f.nom = ? and n.numero = ?
    ', [$r->filiere, $r->dernier]);
    $out .= sprintf("  %-24s derniere annee = %s  ->  %d etudiants\n",
        $r->filiere, $r->dernier, $cnt[0]->c ?? 0);
}

file_put_contents(__DIR__ . '/_level.txt', $out);
echo "written\n";
