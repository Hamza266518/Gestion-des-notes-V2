<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Total etudiants: " . \App\Models\Etudiant::count() . "\n";
echo "Total users (role=etudiant): " . \App\Models\User::where('role', 'etudiant')->count() . "\n";

$annee = \App\Models\AnneeAcademique::where('is_current', 1)->first();
echo "Current annee: " . ($annee ? $annee->label : 'none') . "\n";

if ($annee) {
    $count = \App\Models\Etudiant::where('annee_academique_id', $annee->id)->count();
    echo "Etudiants for current annee: {$count}\n";
}

$filieres = \App\Models\Filiere::count();
echo "Total filieres: {$filieres}\n";
