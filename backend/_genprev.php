<?php

/** One-off: rebuild the evaluation previews handed over for review. */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\StageGrilleDocxService;

$ident = [
    'nom_prenom'         => 'HAJI NABIL',
    'numero_inscription' => '39 IP24',
    'section'            => 'Technicien Specialise',
    'filiere'            => 'Infirmier Polyvalent (3ème année)',
    'formation'          => '2023/2026',
    'annee_scolaire'     => '2025/2026',
];

$opts = [
    'duree'    => '4 semaines',
    'lieu'     => 'Edderrak',
    'service'  => 'Médecine',
    'pratique' => 'Pose de perfusion',
];

$filled = $opts + [
    'data' => [
        'comportement'      => [3, 4, 3, 4, 3],
        'aptitudes'         => [3, 3, 4, 3, 4, 3, 3, 4, 3, 4],
        'eval_pratique_1'   => 14,
        'eval_pratique_2'   => 15,
        'pratique_executee' => 'Pose de perfusion',
        'observation'       => '',
    ],
];

$blank = $opts + ['data' => null];

$service = app(StageGrilleDocxService::class);
$target = __DIR__ . '/../apercus';

$jobs = [
    'evaluation-remplie.docx' => [$ident, $filled],
    'evaluation-vide.docx'    => [$ident, $blank],
];

foreach ($jobs as $name => [$id, $options]) {
    $path = $service->buildEvaluation($id, $options);
    copy($path, $target . '/' . $name);
    @unlink($path);

    echo $name . ' ' . filesize($target . '/' . $name) . " bytes\n";
}
