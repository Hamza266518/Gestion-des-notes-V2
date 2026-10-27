<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Etudiant;
use App\Models\Unite;
use App\Models\Filiere;
use App\Models\Groupe;
use Illuminate\Support\Facades\App;

$etu = Etudiant::where('nom_prenom', 'like', '%HAJI%NABIL%')->first();
if (!$etu) {
    echo "NOT FOUND\n";
    exit;
}

$groupe = $etu->groupe()->with('niveau.filiere')->first();
$filiere = $groupe->niveau->filiere;
$niveau = $groupe->niveau->numero;

echo "ETUDIANT: " . $etu->nom_prenom . " (ID:" . $etu->id . ")\n";
echo "GROUPE: " . $groupe->nom . " | FILIERE: " . $filiere->nom . " | NIVEAU: " . $niveau . "\n\n";

echo "=== UNITES (DB) ===\n";
$unites = Unite::where('filiere_id', $filiere->id)
    ->where('numero_annee', $niveau)
    ->where('is_active', true)
    ->orderBy('ordre')
    ->get(['id', 'nom', 'coefficient', 'semestre']);

foreach ($unites as $u) {
    echo 'U' . $u->id . ' | ' . str_pad($u->nom, 45) . ' | coef=' . $u->coefficient . ' | S' . $u->semestre . "\n";
}

echo "\n=== RECAP MPCC1 ===\n";
$ctrl = App::make(App\Http\Controllers\Admin\NoteAdminController::class);
$ref = new ReflectionMethod($ctrl, 'computeRecapData');
$ref->setAccessible(true);
$d1 = $ref->invoke($ctrl, $groupe->id, 'mpcc1');
foreach ($d1['subjects'] as $s) {
    echo $s['nom'] . ' | coef=' . $s['coefficient'] . "\n";
}
echo "ROW: avg=" . var_export($d1['students'][array_search($etu->id, array_column($d1['students'], 'id'))]['average'] ?? 'NOT FOUND', true) . "\n";

echo "\n=== RECAP MPCC2 ===\n";
$d2 = $ref->invoke($ctrl, $groupe->id, 'mpcc2');
foreach ($d2['subjects'] as $s) {
    echo $s['nom'] . ' | coef=' . $s['coefficient'] . "\n";
}
$idx2 = array_search($etu->id, array_column($d2['students'], 'id'));
echo "ROW: avg=" . var_export($d2['students'][$idx2]['average'] ?? 'NOT FOUND', true) . "\n";

echo "\n=== RECAP MPCC GLOBAL ===\n";
$dg = $ref->invoke($ctrl, $groupe->id, 'mpcc_global');
foreach ($dg['subjects'] as $s) {
    echo $s['nom'] . ' | coef=' . $s['coefficient'] . "\n";
}
$idxg = array_search($etu->id, array_column($dg['students'], 'id'));
echo "ROW: avg=" . var_export($dg['students'][$idxg]['average'] ?? 'NOT FOUND', true) . "\n";

echo "\n=== BULLETIN (full year) ===\n";
$bs = App::make(App\Services\BulletinService::class);
$bulletin = $bs->calculateBulletin($etu->id, $groupe->annee_academique_id);
echo "moyenne_cc=" . var_export($bulletin['moyenne_cc'], true) . " | moyenne_generale=" . var_export($bulletin['moyenne_generale'], true) . "\n";
echo "UNITS IN BULLETIN:\n";
foreach ($bulletin['unites'] as $u) {
    echo '  ' . $u['nom'] . ' | coef=' . $u['coefficient'] . ' | S' . $u['semestre'] . ' | moy_cc=' . $u['moyenne_cc'] . "\n";
}
