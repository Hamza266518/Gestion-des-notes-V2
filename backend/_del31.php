<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$ids = [680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,698,699,
        700,701,702,703,704,705,706,707,708,709,710];

$out = "=== SUPPRESSION DES 31 AS HORS LAUREATS ===\n\n";
$out .= "base : " . DB::connection()->getDatabaseName() . "\n\n";

DB::beginTransaction();

try {
    $nbExamens = DB::table('examens')->whereIn('etudiant_id', $ids)->delete();
    $nbNotes   = DB::table('notes')->whereIn('etudiant_id', $ids)->delete();
    $nbEval    = DB::table('stage_evaluations')->whereIn('etudiant_id', $ids)->delete();
    $nbStage   = DB::table('stage_notes')->whereIn('etudiant_id', $ids)->delete();
    $nbDipl    = DB::table('diplomes')->whereIn('etudiant_id', $ids)->delete();
    $nbEtu     = DB::table('etudiants')->whereIn('id', $ids)->delete();

    DB::commit();

    $out .= sprintf("  examens supprimes           : %d\n", $nbExamens);
    $out .= sprintf("  notes supprimees            : %d\n", $nbNotes);
    $out .= sprintf("  stage_evaluations supprimees: %d\n", $nbEval);
    $out .= sprintf("  stage_notes supprimees      : %d\n", $nbStage);
    $out .= sprintf("  diplomes supprimes          : %d\n", $nbDipl);
    $out .= sprintf("  etudiants supprimes         : %d\n", $nbEtu);
} catch (\Throwable $e) {
    DB::rollBack();
    $out .= "  ERREUR, rollback : " . $e->getMessage() . "\n";
}

// Verification
$out .= "\n=== APRES ===\n";
$out .= sprintf("  etudiants en base           : %d\n", DB::table('etudiants')->count());
$out .= sprintf("  notes en base               : %d\n", DB::table('notes')->count());
$out .= sprintf("  des 31 encore presents      : %d\n", DB::table('etudiants')->whereIn('id', $ids)->count());

// Le pool diplomant
$pool = DB::select('
    select count(e.id) as n
    from etudiants e
    join groupes g on g.id = e.groupe_id
    join niveaux n on n.id = g.niveau_id
    join filieres f on f.id = n.filiere_id
    where e.annee_academique_id = 1 and n.numero = f.nombre_annees
');
$out .= sprintf("  pool annee diplomante       : %d\n", $pool[0]->n);

// Les 76 laureats sont-ils intacts ?
$officiels = json_decode(file_get_contents(__DIR__ . '/_laureats_officiels.json'), true)['numeros'];
$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$offNorm = [];
foreach ($officiels as $o) { $offNorm[$norm($o)] = true; }
$intacts = 0;
$laurIds = [];
foreach (DB::table('etudiants')->get() as $e) {
    if (isset($offNorm[$norm($e->numero_inscription)])) { $intacts++; $laurIds[] = $e->id; }
}
$out .= sprintf("  laureats officiels presents : %d / 76\n", $intacts);
if ($laurIds !== []) {
    $out .= sprintf("  notes des laureats          : %d\n", DB::table('notes')->whereIn('etudiant_id', $laurIds)->count());
}

file_put_contents(__DIR__ . '/_del31.txt', $out);
echo "written\n";
