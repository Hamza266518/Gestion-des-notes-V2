<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$out = "=== TABLES ===\n";
foreach (DB::select('show tables') as $t) {
    $out .= "  " . array_values((array) $t)[0] . "\n";
}

$out .= "\n=== COLONNES controles ===\n";
foreach (DB::select('show columns from controles') as $c) {
    $out .= sprintf("  %-24s %s\n", $c->Field, $c->Type);
}

$out .= "\n=== COLONNES notes ===\n";
foreach (DB::select('show columns from notes') as $c) {
    $out .= sprintf("  %-24s %s\n", $c->Field, $c->Type);
}

$out .= "\n=== COLONNES etudiants ===\n";
foreach (DB::select('show columns from etudiants') as $c) {
    $out .= sprintf("  %-24s %s\n", $c->Field, $c->Type);
}

file_put_contents(__DIR__ . '/_schema.txt', $out);
echo "written\n";
