<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$ids = [680,681,682,683,684,685,686,687,688,689,690,691,692,693,694,695,696,697,698,699,
        700,701,702,703,704,705,706,707,708,709,710];

$db = DB::connection()->getDatabaseName();

$snapshot = [
    'captured_at' => date('c'),
    'database'    => $db,
    'reason'      => 'suppression des 31 AS hors liste officielle LAUREATS_2025_2026_CIN_EXACT',
    'etudiants'   => DB::table('etudiants')->whereIn('id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
    'notes'       => DB::table('notes')->whereIn('etudiant_id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
    'examens'     => DB::table('examens')->whereIn('etudiant_id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
    'stage_evaluations' => DB::table('stage_evaluations')->whereIn('etudiant_id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
    'stage_notes'       => DB::table('stage_notes')->whereIn('etudiant_id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
    'diplomes'          => DB::table('diplomes')->whereIn('etudiant_id', $ids)->get()->map(fn ($r) => (array) $r)->all(),
];

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$dir = __DIR__ . '/../sauvegardes';
@mkdir($dir, 0777, true);
$file = $dir . '/31-AS-hors-laureats-' . date('Ymd-His') . '.json';
file_put_contents($file, $json);

printf("snapshot : %s\n", $file);
printf("etudiants=%d notes=%d examens=%d\n",
    count($snapshot['etudiants']), count($snapshot['notes']), count($snapshot['examens']));
