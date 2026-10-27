<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// La liste officielle des 76 laureats 2025/2026, relevee dans le PDF.
$laureats = [
    // Aide-Soignant : 41
    '96AS26','97AS26','99AS26','100AS26','101AS26','102AS26','103AS26','105AS26','106AS26','109AS26',
    '110AS26','111AS26','112AS26','113AS26','114AS26','115AS26','116AS26','117AS26','119AS26','121AS26',
    '122AS26','123AS26','124AS26','125AS26','126AS26','127AS26','128AS26','129AS26','130AS26','131AS26',
    '132AS26','133AS26','135AS26','136AS26','137AS26','138AS26','139AS26','141AS26','142AS26','143AS26',
    '144AS26',
    // Infirmier Polyvalent : 29
    '39 IP24','40 IP24','41 IP24','42 IP24','43 IP24','44 IP24','45 IP24','46 IP24','47 IP24','48 IP24',
    '49 IP24','50 IP24','51 IP24','52 IP24','53 IP24','54 IP24','55 IP24','56 IP24','57 IP24','59 IP24',
    '60 IP24','61 IP24','62 IP24','63 IP24','65 IP24','68 IP24','73 IP24','74 IP24','75 IP24',
    // Infirmier Auxiliaire : 6
    '10 IA25','11 IA25','12 IA25','13 IA25','14 IA25','15 IA25',
];

// Normalisation : enlever les espaces, mettre en majuscules.
$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$laureatsNorm = array_map($norm, $laureats);

$etudiants = DB::select('
    select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.annee_academique_id,
           g.id as gid, g.nom as gnom, g.promotion, n.numero as niveau, f.nom as filiere,
           a.label as annee
    from etudiants e
    left join groupes g on g.id = e.groupe_id
    left join niveaux n on n.id = g.niveau_id
    left join annees_academiques a on a.id = e.annee_academique_id
    left join filieres f on f.id = n.filiere_id
');

$byNum = [];
foreach ($etudiants as $e) {
    $byNum[$norm($e->numero_inscription)] = $e;
}

$out = "=== LES 76 LAUREATS 2025/2026 DANS LA BASE ===\n\n";
$trouves = 0;
$manquants = [];

foreach ($laureatsNorm as $num) {
    if (isset($byNum[$num])) {
        $e = $byNum[$num];
        $trouves++;
        $out .= sprintf("  OK  %-12s %-28s id=%-5d %-24s niv%-3s [%s]\n",
            $e->numero_inscription, $e->nom_prenom, $e->id, $e->filiere ?? '-', $e->niveau ?? '-', $e->annee ?? '-');
    } else {
        $manquants[] = $num;
    }
}

$out .= "\n  Retrouves dans la base : $trouves / 76\n";
$out .= "  Introuvables : " . count($manquants) . "\n";
foreach ($manquants as $m) {
    $out .= "     - $m\n";
}

// Les notes de ces laureats
$ids = [];
foreach ($laureatsNorm as $num) {
    if (isset($byNum[$num])) {
        $ids[] = $byNum[$num]->id;
    }
}
if ($ids !== []) {
    $out .= "\n  Notes de ces laureats : " . DB::table('notes')->whereIn('etudiant_id', $ids)->count() . "\n";

    // Examens lies aux controles de leurs notes
    $ctrlIds = DB::table('notes')->whereIn('etudiant_id', $ids)->pluck('controle_id')->unique()->all();
    $out .= "  Controles concernes : " . count($ctrlIds) . "\n";
}

file_put_contents(__DIR__ . '/_laureats_db.txt', $out);
echo "written\n";
