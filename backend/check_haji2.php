<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Etudiant;
use App\Models\Unite;
use Illuminate\Support\Facades\App;

$etu = Etudiant::where('nom_prenom', 'like', '%HAJI%NABIL%')->first();
if (!$etu) {
    echo "NOT FOUND\n";
    exit;
}

$groupe = $etu->groupe()->with('niveau.filiere')->first();
$filiere = $groupe->niveau->filiere;
$niveau = $groupe->niveau->numero;

echo "ETUDIANT: {$etu->nom_prenom} (ID:{$etu->id})\n";
echo "GROUPE: {$groupe->nom} | FILIERE: {$filiere->nom} | NIVEAU: {$niveau}\n\n";

echo "=== UNITES DB ===\n";
$unites = Unite::where('filiere_id', $filiere->id)
    ->where('numero_annee', $niveau)
    ->where('is_active', true)
    ->orderBy('ordre')
    ->get(['id', 'nom', 'coefficient', 'semestre']);

foreach ($unites as $u) {
    echo "U{$u->id} | " . str_pad($u->nom, 40) . " | coef={$u->coefficient} | S{$u->semestre}\n";
}

$ctrl = App::make(App\Http\Controllers\Admin\NoteAdminController::class);
$ref = new ReflectionMethod($ctrl, 'computeRecapData');
$ref->setAccessible(true);

function show($ctrl, $ref, $groupeId, $type, $etuId) {
    $d = $ref->invoke($ctrl, $groupeId, $type);
    echo "\n=== {$type} ===\n";
    foreach ($d['subjects'] as $s) {
        echo " - {$s['nom']} | coef={$s['coefficient']}\n";
    }
    $idx = array_search($etuId, array_column($d['students'], 'id'));
    if ($idx !== false) {
        echo "ROW AVG: " . $d['students'][$idx]['average'] . "\n";
    }
}

show($ctrl, $ref, $groupe->id, 'mpcc1', $etu->id);
show($ctrl, $ref, $groupe->id, 'mpcc2', $etu->id);
show($ctrl, $ref, $groupe->id, 'mpcc_global', $etu->id);

$bs = App::make(App\Services\BulletinService::class);
$b = $bs->calculateBulletin($etu->id, $groupe->annee_academique_id);
echo "\n=== BULLETIN (full year) ===\n";
echo "moyenne_cc=" . $b['moyenne_cc'] . " | moyenne_generale=" . $b['moyenne_generale'] . "\n";
echo "UNITS IN BULLETIN:\n";
foreach ($b['unites'] as $u) {
    echo "  {$u['nom']} | coef={$u['coefficient']} | S{$u['semestre']} | moy_cc={$u['moyenne_cc']}\n";
}
