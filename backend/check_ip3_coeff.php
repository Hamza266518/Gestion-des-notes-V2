<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Etudiant;
use App\Models\Unite;
use App\Models\Groupe;
use Illuminate\Support\Facades\App;

$etu = Etudiant::find(639);
if (!$etu) {
    $etu = Etudiant::where('nom_prenom', 'LIKE', '%HAJI%')->where('nom_prenom', 'LIKE', '%NABIL%')->first();
}

if (!$etu) {
    echo "NOT FOUND\n";
    exit;
}

$groupe = Groupe::with('niveau.filiere')->find($etu->groupe_id);
if (!$groupe) {
    $etu->load('groupe.niveau.filiere');
    $groupe = $etu->groupe;
}

$filiere = $groupe->niveau->filiere;
$niveau = $groupe->niveau->numero;

echo "ETUDIANT: {$etu->nom_prenom} (ID:{$etu->id}) | N°: {$etu->numero_inscription}\n";
echo "GROUPE: {$groupe->nom} | FILIERE: {$filiere->nom} | NIVEAU: {$niveau}\n\n";

echo "=== UNITES IN DB (active, same filiere/annee) ===\n";
$unites = Unite::where('filiere_id', $filiere->id)
    ->where('numero_annee', $niveau)
    ->where('is_active', true)
    ->orderBy('ordre')
    ->get(['id', 'nom', 'coefficient', 'semestre', 'ordre']);

foreach ($unites as $u) {
    printf("U%-3d | %-40s | coef=%2d | S%d\n", $u->id, $u->nom, $u->coefficient, $u->semestre);
}

$ctrl = App::make(App\Http\Controllers\Admin\NoteAdminController::class);
$ref = new ReflectionMethod($ctrl, 'computeRecapData');
$ref->setAccessible(true);

function show($ctrl, $ref, $groupeId, $type, $etuId) {
    $d = $ref->invoke($ctrl, $groupeId, $type);
    echo "\n=== RECAP {$type} ===\n";
    echo "Subjects (coef):\n";
    foreach ($d['subjects'] as $s) {
        printf("  - %-40s | coef=%s\n", $s['nom'], $s['coefficient']);
    }
    
    $idx = array_search($etuId, array_column($d['students'], 'id'));
    if ($idx !== false) {
        echo "Haji Nabil row average: " . ($d['students'][$idx]['average'] ?? 'NULL') . "\n";
    } else {
        echo "Haji Nabil row: NOT FOUND\n";
    }
    
    if (isset($d['class_average']) && $d['class_average']) {
        echo "Class overall: " . ($d['class_average']['overall'] ?? 'NULL') . "\n";
    }
}

show($ctrl, $ref, $groupe->id, 'mpcc1', $etu->id);
show($ctrl, $ref, $groupe->id, 'mpcc2', $etu->id);
show($ctrl, $ref, $groupe->id, 'mpcc_global', $etu->id);

$bs = App::make(App\Services\BulletinService::class);
$b = $bs->calculateBulletin($etu->id, $groupe->annee_academique_id);

echo "\n=== BULLETIN (full year, calculated by BulletinService) ===\n";
echo "moyenne_cc = " . ($b['moyenne_cc'] ?? 'NULL') . "\n";
echo "moyenne_theorique = " . ($b['moyenne_theorique'] ?? 'NULL') . "\n";
echo "moyenne_pratique = " . ($b['moyenne_pratique'] ?? 'NULL') . "\n";
echo "moyenne_generale = " . ($b['moyenne_generale'] ?? 'NULL') . "\n";
echo "has_soutenance = " . ($b['has_soutenance'] ? 'true' : 'false') . "\n\n";

echo "Units in bulletin with their coeff and moy_cc:\n";
foreach ($b['unites'] as $u) {
    printf("  %-40s | coef=%2d | S%d | moy_cc=%s | moy_theo=%s | moy_prat=%s\n", 
        $u['nom'], 
        $u['coefficient'], 
        $u['semestre'], 
        $u['moyenne_cc'] ?? 'NULL',
        $u['moyenne_theorique'] ?? 'NULL',
        $u['moyenne_pratique'] ?? 'NULL'
    );
}

echo "\n=== VERIFICATION ===\n";
echo "Recap uses unite.coefficient directly from DB. Bulletin uses unite.coefficient directly from DB.\n";
echo "So they should be identical. Any difference would mean different unit sets or different filtering.\n";
