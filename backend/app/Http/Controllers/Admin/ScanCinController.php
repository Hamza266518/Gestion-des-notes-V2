<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\GeminiTransportException;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\CinScanNormalizer;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\NumeroInscriptionService;
use App\Traits\ValidatesScanUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

class ScanCinController extends Controller
{
    use ValidatesScanUploads;
    protected $gemini;
    protected $parser;
    protected $numeroService;
    protected $normalizer;

    public function __construct(
        GeminiService $gemini,
        NoteParserService $parser,
        NumeroInscriptionService $numeroService,
        CinScanNormalizer $normalizer
    ) {
        $this->gemini        = $gemini;
        $this->parser        = $parser;
        $this->numeroService = $numeroService;
        $this->normalizer    = $normalizer;
    }

    public function scan(Request $request)
    {
        try {
            $request->validate([
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'filiere_code'        => 'required|string',
            ]);

            if ($request->hasFile('fichiers')) {
                $pdfs = $request->file('fichiers');
            } else if ($request->hasFile('pdfs')) {
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
                    'message' => 'Aucun fichier envoyé',
                ], 422);
            }

            if (!is_array($pdfs)) {
                $pdfs = [$pdfs];
            }

            $validated = $this->validateScanUploads($pdfs);
            if ($validated !== null) {
                return $validated;
            }

            $results = [];
            $errors  = [];

            foreach ($pdfs as $index => $pdf) {
                $base64 = base64_encode(file_get_contents($pdf->getRealPath()));
                $mime   = $pdf->getMimeType() ?: 'application/octet-stream';

                try {
                    $rawText = $this->gemini->scanCin($base64, $mime);
                } catch (GeminiTransportException $e) {
                    return response()->json([
                        'success' => false,
                        'message' => "Connexion sécurisée à l'API Gemini impossible. Vérifiez le certificat CA (CA_BUNDLE_PATH) et la clé API.",
                    ], 502);
                }

                $students = $this->parser->parseCin($rawText);

                if (empty($students)) {
                    $errors[] = ['message' => 'Document illisible'];
                    \Log::warning('ScanCin: no students parsed', ['file_index' => $index]);
                    continue;
                }

                foreach ($students as $student) {
                    $data = $this->normalizer->normalize($student);

                    if (empty($data['nom_prenom'])) {
                        $errors[] = ['message' => 'Nom manquant pour un étudiant'];
                        continue;
                    }

                    if (empty($data['cin'])) {
                        $errors[] = ['message' => 'CIN illisible pour ' . $data['nom_prenom']];
                        continue;
                    }

                    $numero = $this->numeroService->generate(
                        $request->filiere_code,
                        $request->annee_academique_id
                    );

                    $results[] = array_merge($data, [
                        'numero_inscription'  => $numero,
                        'groupe_id'           => $request->groupe_id,
                        'annee_academique_id' => $request->annee_academique_id,
                    ]);
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
                $cin = $this->normalizer->normalizeCin($item['cin']);

                if ($cin === '') {
                    $skipped++;
                    continue;
                }

                if (Etudiant::where('cin', $cin)->exists()) {
                    $skipped++;
                    continue;
                }

                $email = strtolower(str_replace(' ', '.', $item['nom_prenom'])) . '@ifp.ma';

                $password = User::generateStrongPassword();

                $user = User::create([
                    'name'               => $item['nom_prenom'],
                    'email'              => $email,
                    'password'           => Hash::make($password),
                    'password_encrypted' => Crypt::encryptString($password),
                    'password_original_encrypted' => Crypt::encryptString($password),
                    'role'               => 'etudiant',
                ]);

                $data = $this->normalizer->normalize(array_merge($item, ['cin' => $cin]));

                Etudiant::create([
                    'user_id'             => $user->id,
                    'groupe_id'           => $item['groupe_id'],
                    'annee_academique_id' => $item['annee_academique_id'],
                    'numero_inscription'  => $item['numero_inscription'],
                    'cin'                 => $cin,
                    'nom_ar'              => $data['nom_ar'] ?: null,
                    'date_naissance'      => $data['date_naissance'],
                    'date_naissance_ar'   => $data['date_naissance_ar'] ?: null,
                    'lieu_naissance'      => $data['lieu_naissance'] ?: null,
                    'lieu_naissance_ar'   => $data['lieu_naissance_ar'] ?: null,
                    'nationalite'         => $data['nationalite'],
                    'nationalite_ar'      => $data['nationalite_ar'] ?: null,
                    'date_inscription'    => $data['date_inscription'],
                    'date_inscription_ar' => $data['date_inscription_ar'] ?: null,
                    'cin_ar'              => $data['cin_ar'] ?: null,
                    'numero_inscription_ar' => $data['numero_inscription_ar'] ?: null,
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