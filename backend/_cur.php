<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\AnneeAcademique;

config(['database.connections.mysql.database' => 'Gestion_des_notes_v2_test']);
DB::purge('mysql');
DB::reconnect('mysql');

$out = "DB : " . DB::connection()->getDatabaseName() . "\n\n";

$out .= "SELECT id,label,is_current FROM annees_academiques :\n";
foreach (DB::select('select id, label, is_current from annees_academiques order by id') as $r) {
    $out .= sprintf("  id=%-5d %-16s is_current=%d\n", $r->id, $r->label, $r->is_current);
}

$current = AnneeAcademique::where('is_current', true)->first();
$out .= "\nAnneeAcademique::where('is_current', true)->first() : "
    . ($current ? "id={$current->id} label={$current->label}" : '(aucune)') . "\n";

$out .= "\nEtudiants de l'annee courante : "
    . ($current ? DB::table('etudiants')->where('annee_academique_id', $current->id)->count() : 0) . "\n";

file_put_contents(__DIR__ . '/_cur.txt', $out);
echo $out;
