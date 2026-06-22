<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\NumeroInscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        try {
            $request->validate([
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'filiere_code'        => 'required|string',
            ]);

            if ($request->hasFile('pdfs')) {
                $pdfs = $request->file('pdfs');
            } else if ($request->hasFile('pdf')) {
                $pdfs = [$request->file('pdf')];
            } else if ($request->hasFile('images')) {
                $pdfs = $request->file('images');
            } else if ($request->hasFile('image')) {
                $pdfs = [$request->file('image')];
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun fichier PDF trouvé dans la requête',
                ], 422);
            }
            
            if (!is_array($pdfs)) {
                $pdfs = [$pdfs];
            }

            $results = [];
            $errors  = [];

            foreach ($pdfs as $pdf) {
                $base64 = base64_encode(file_get_contents($pdf->getRealPath()));
                $rawText = $this->gemini->scanCin($base64);
                $students = $this->parser->parseCin($rawText);

                if (empty($students)) {
                    $errors[] = ['message' => 'Document illisible'];
                    \Log::warning('ScanCin: no students parsed', ['raw' => $rawText]);
                    continue;
                }

                foreach ($students as $student) {
                    if (empty($student['nom_prenom'])) {
                        $errors[] = ['message' => 'Nom manquant pour un étudiant'];
                        continue;
                    }

                    $numero = $this->numeroService->generate(
                        $request->filiere_code,
                        $request->annee_academique_id
                    );

                    $results[] = [
                        'nom_prenom'          => $student['nom_prenom'] ?? '',
                        'nom_ar'              => $student['nom_ar'] ?? '',
                        'cin'                 => strtoupper($student['cin'] ?? ''),
                        'cin_ar'              => $student['cin_ar'] ?? '',
                        'date_naissance'      => $student['date_naissance'] ?? '',
                        'date_naissance_ar'   => $student['date_naissance_ar'] ?? '',
                        'lieu_naissance'      => $student['lieu_naissance'] ?? '',
                        'lieu_naissance_ar'   => $student['lieu_naissance_ar'] ?? '',
                        'nationalite'         => $student['nationalite'] ?? '',
                        'nationalite_ar'      => $student['nationalite_ar'] ?? '',
                        'date_inscription'    => $student['date_inscription'] ?? '',
                        'date_inscription_ar' => $student['date_inscription_ar'] ?? '',
                        'numero_inscription'  => $numero,
                        'numero_inscription_ar' => $student['numero_inscription_ar'] ?? '',
                        'groupe_id'           => $request->groupe_id,
                        'annee_academique_id' => $request->annee_academique_id,
                    ];
                }
            }

            // Check for existing CINs
            $cins = array_filter(array_column($results, 'cin'));
            if (!empty($cins)) {
                $existing = Etudiant::whereIn('cin', $cins)->get(['cin', 'nom_prenom', 'numero_inscription'])->keyBy('cin');
                foreach ($results as &$r) {
                    $cin = $r['cin'] ?? '';
                    if ($cin && isset($existing[$cin])) {
                        $r['existing'] = true;
                        $r['existing_student'] = $existing[$cin]->nom_prenom;
                        $r['existing_numero'] = $existing[$cin]->numero_inscription;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data'    => ['resultats' => $results, 'erreurs' => $errors],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur scan CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan CIN: ' . $e->getMessage()], 500);
        }
    }

    public function confirm(Request $request)
    {
        try {
            $request->validate([
                'stagiaires'                            => 'required|array',
                'stagiaires.*.nom_prenom'               => 'required|string',
                'stagiaires.*.nom_ar'                   => 'nullable|string',
                'stagiaires.*.cin'                      => 'required|string',
                'stagiaires.*.date_naissance'           => 'nullable|date',
                'stagiaires.*.numero_inscription'       => 'required|string',
                'stagiaires.*.groupe_id'                => 'required|exists:groupes,id',
                'stagiaires.*.annee_academique_id'      => 'required|exists:annees_academiques,id',
                'stagiaires.*.date_naissance_ar'        => 'nullable|string',
                'stagiaires.*.lieu_naissance'           => 'nullable|string',
                'stagiaires.*.lieu_naissance_ar'        => 'nullable|string',
                'stagiaires.*.nationalite'              => 'nullable|string',
                'stagiaires.*.nationalite_ar'           => 'nullable|string',
                'stagiaires.*.date_inscription'         => 'nullable|string',
                'stagiaires.*.cin_ar'                   => 'nullable|string',
                'stagiaires.*.numero_inscription_ar'    => 'nullable|string',
                'stagiaires.*.date_inscription_ar'      => 'nullable|string',
            ]);

            $created = 0;
            $skipped = 0;

            foreach ($request->stagiaires as $item) {
                $cin = strtoupper($item['cin']);

                // Skip if student with this CIN already exists
                if (Etudiant::where('cin', $cin)->exists()) {
                    $skipped++;
                    continue;
                }

                $email    = strtolower(str_replace(' ', '.', $item['nom_prenom'])) . '@ifp.ma';

                $password = User::generateStrongPassword();

                $user = User::create([
                    'name'               => $item['nom_prenom'],
                    'email'              => $email,
                    'password'           => Hash::make($password),
                    'password_encrypted' => Crypt::encryptString($password),
                    'password_original_encrypted' => Crypt::encryptString($password),
                    'role'               => 'etudiant',
                ]);

                Etudiant::create([
                    'user_id'             => $user->id,
                    'groupe_id'           => $item['groupe_id'],
                    'annee_academique_id' => $item['annee_academique_id'],
                    'nom_prenom'          => $item['nom_prenom'],
                    'nom_ar'              => $item['nom_ar'] ?? null,
                    'cin'                 => $cin,
                    'date_naissance'      => $item['date_naissance'] ?? null,
                    'numero_inscription'  => $item['numero_inscription'],
                    'date_naissance_ar'        => $item['date_naissance_ar'] ?? null,
                    'lieu_naissance'           => $item['lieu_naissance'] ?? null,
                    'lieu_naissance_ar'        => $item['lieu_naissance_ar'] ?? null,
                    'nationalite'              => $item['nationalite'] ?? null,
                    'nationalite_ar'           => $item['nationalite_ar'] ?? null,
                    'date_inscription'         => $item['date_inscription'] ?? null,
                    'date_inscription_ar'      => $item['date_inscription_ar'] ?? null,
                    'cin_ar'                   => $item['cin_ar'] ?? null,
                    'numero_inscription_ar'    => $item['numero_inscription_ar'] ?? null,
                ]);

                $created++;
            }

            $parts = [];
            if ($created > 0) $parts[] = "{$created} créé(s)";
            if ($skipped > 0) $parts[] = "{$skipped} ignoré(s) (déjà existant)";

            return response()->json([
                'success' => true,
                'data'    => ['crees' => $created, 'ignores' => $skipped],
                'message' => 'Stagiaires enregistrés. ' . implode(', ', $parts),
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur confirmation scan CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'enregistrement des stagiaires'], 500);
        }
    }

    public function checkExisting(Request $request): JsonResponse
    {
        try {
            $cins = $request->input('cins', []);
            if (!is_array($cins) || empty($cins)) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $existing = Etudiant::whereIn('cin', $cins)
                ->get(['cin', 'nom_prenom', 'numero_inscription'])
                ->keyBy('cin');

            return response()->json([
                'success' => true,
                'data' => $existing,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur checkExisting CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'data' => []], 500);
        }
    }
}