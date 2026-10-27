<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$db = DB::connection()->getDatabaseName();

$snapshot = [
    'captured_at' => date('c'),
    'database'    => $db,
    'annees_academiques' => DB::table('annees_academiques')->get()->map(fn ($r) => (array) $r)->all(),
    'niveaux'            => DB::table('niveaux')->get()->map(fn ($r) => (array) $r)->all(),
    'filieres'           => DB::table('filieres')->get()->map(fn ($r) => (array) $r)->all(),
    'groupes'            => DB::table('groupes')->get()->map(fn ($r) => (array) $r)->all(),
    'etudiants'          => DB::table('etudiants')->get()->map(fn ($r) => (array) $r)->all(),
    'notes'              => DB::table('notes')->get()->map(fn ($r) => (array) $r)->all(),
    'controles'          => DB::table('controles')->get()->map(fn ($r) => (array) $r)->all(),
    'examens'            => DB::table('examens')->get()->map(fn ($r) => (array) $r)->all(),
    'diplomes'           => DB::table('diplomes')->get()->map(fn ($r) => (array) $r)->all(),
    'stage_evaluations'  => DB::table('stage_evaluations')->get()->map(fn ($r) => (array) $r)->all(),
    'stage_notes'        => DB::table('stage_notes')->get()->map(fn ($r) => (array) $r)->all(),
];

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$dir = __DIR__ . '/../sauvegardes';
@mkdir($dir, 0777, true);
$file = $dir . '/dev-avant-2026-2027-' . date('Ymd-His') . '.json';
file_put_contents($file, $json);

foreach (['annees_academiques','niveaux','filieres','groupes','etudiants','notes','controles','examens','diplomes','stage_evaluations','stage_notes'] as $t) {
    printf("  %-22s %d\n", $t, count($snapshot[$t]));
}
printf("\nsnapshot : %s (%d Mo)\n", $file, round(strlen($json) / 1048576, 2));
