<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\NumeroInscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ScanCinController extends Controller
{
    protected $gemini;
    protected $parser;
    protected $numeroService;

    public function __construct(GeminiService $gemini, NoteParserService $parser, NumeroInscriptionService $numeroService)
    {
        $this->gemini        = $gemini;
        $this->parser        = $parser;
        $this->numeroService = $numeroService;
    }

    public function scan(Request $request)
    {
        $request->validate([
            'groupe_id'           => 'required|exists:groupes,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'filiere_code'        => 'required|string',
        ]);

        // Check if images is sent as array or single file
        if ($request->hasFile('images')) {
            $images = $request->file('images');
        } else if ($request->hasFile('image')) {
            $images = [$request->file('image')];
        } else {
            return response()->json([
                'success' => false,
                'message' => 'No image found in request',
                'debug' => [
                    'has_images' => $request->hasFile('images'),
                    'has_image' => $request->hasFile('image'),
                    'all_files' => array_keys($request->allFiles()),
                    'request_data' => $request->except(['images', 'image'])
                ]
            ], 422);
        }
        
        if (!is_array($images)) {
            $images = [$images];
        }

        $results = [];
        $errors  = [];

        foreach ($images as $image) {
            $base64 = base64_encode(file_get_contents($image->getRealPath()));
            $rawText = $this->gemini->scanCin($base64);
            $students = $this->parser->parseCin($rawText);

            if (empty($students)) {
                $errors[] = ['message' => 'CIN illisible'];
                \Log::warning('ScanCin: no students parsed', ['raw' => $rawText]);
                continue;
            }

            foreach ($students as $student) {
                if (empty($student['cin'])) {
                    $errors[] = ['message' => 'CIN manquant pour un étudiant'];
                    continue;
                }

                $numero = $this->numeroService->generate(
                    $request->filiere_code,
                    $request->annee_academique_id
                );

                $results[] = [
                    'nom_prenom'         => $student['nom_prenom'] ?? '',
                    'cin'                => strtoupper($student['cin']),
                    'date_naissance'     => $student['date_naissance'] ?? '',
                    'numero_inscription' => $numero,
                    'groupe_id'          => $request->groupe_id,
                    'annee_academique_id'=> $request->annee_academique_id,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data'    => ['resultats' => $results, 'erreurs' => $errors],
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'stagiaires'                       => 'required|array',
            'stagiaires.*.nom_prenom'          => 'required|string',
            'stagiaires.*.cin'                 => 'required|string',
            'stagiaires.*.date_naissance'      => 'nullable|date',
            'stagiaires.*.numero_inscription'  => 'required|string',
            'stagiaires.*.groupe_id'           => 'required|exists:groupes,id',
            'stagiaires.*.annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        $created = 0;
        $updated = 0;

        foreach ($request->stagiaires as $item) {
            $cin      = strtoupper($item['cin']);
            $email    = strtolower($cin) . '@ifp.ma';
            $password = str_replace(' ', '', $item['numero_inscription']) . substr($cin, 0, 2);

            $user = User::where('email', $email)->first();

            if ($user) {
                $user->update(['name' => $item['nom_prenom']]);
                $updated++;
            } else {
                $user = User::create([
                    'name'     => $item['nom_prenom'],
                    'email'    => $email,
                    'password' => Hash::make($password),
                    'role'     => 'etudiant',
                ]);
                $created++;
            }

            Etudiant::updateOrCreate(
                ['cin' => $cin],
                [
                    'user_id'             => $user->id,
                    'groupe_id'           => $item['groupe_id'],
                    'annee_academique_id' => $item['annee_academique_id'],
                    'nom_prenom'          => $item['nom_prenom'],
                    'cin'                 => $cin,
                    'date_naissance'      => $item['date_naissance'] ?? null,
                    'numero_inscription'  => $item['numero_inscription'],
                ]
            );
        }

        return response()->json([
            'success' => true,
            'data'    => ['crees' => $created, 'mis_a_jour' => $updated],
            'message' => 'Stagiaires enregistrés',
        ]);
    }
}