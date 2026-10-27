<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$rows = App\Models\Filiere::all();
foreach($rows as $x){
    echo $x->id.'|'.$x->code.'|'.$x->section.'|'.$x->type_formation.PHP_EOL;
}
