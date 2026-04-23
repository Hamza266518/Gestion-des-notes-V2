<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CinScanService
{
    protected $gemini;
    protected $parser;
    protected $numeroService;

    public function __construct(
        GeminiService $gemini,
        NoteParserService $parser,
        NumeroInscriptionService $numeroService
    ) {
        $this->gemini        = $gemini;
        $this->parser        = $parser;
        $this->numeroService = $numeroService;
    }

    public function processImage(string $base64Image, int $groupeId, int $anneeAcademiqueId, string $filiereCode): array
    {
        $rawText = $this->gemini->scanCin($base64Image);
        $data    = $this->parser->parseCin($rawText);

        if (empty($data) || empty($data['cin'])) {
            return ['success' => false, 'message' => 'CIN illisible'];
        }

        $numero = $this->numeroService->generate($filiereCode, $anneeAcademiqueId);

        return [
            'success'             => true,
            'nom_prenom'          => $data['nom'] ?? '',
            'cin'                 => strtoupper($data['cin']),
            'numero_inscription'  => $numero,
            'groupe_id'           => $groupeId,
            'annee_academique_id' => $anneeAcademiqueId,
        ];
    }

    public function createEtudiant(array $data): array
    {
        $cin      = strtoupper($data['cin']);
        $email    = strtolower($cin) . '@ifp.ma';
        $password = $data['numero_inscription'] . substr($cin, 0, 2);

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update(['name' => $data['nom_prenom']]);
            $status = 'updated';
        } else {
            $user = User::create([
                'name'     => $data['nom_prenom'],
                'email'    => $email,
                'password' => Hash::make($password),
                'role'     => 'etudiant',
            ]);
            $status = 'created';
        }

        Etudiant::updateOrCreate(
            ['cin' => $cin],
            [
                'user_id'             => $user->id,
                'groupe_id'           => $data['groupe_id'],
                'annee_academique_id' => $data['annee_academique_id'],
                'nom_prenom'          => $data['nom_prenom'],
                'cin'                 => $cin,
                'numero_inscription'  => $data['numero_inscription'],
            ]
        );

        return ['status' => $status, 'email' => $email];
    }
}