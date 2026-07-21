<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Unite;
use App\Models\ActivityLog;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ScanExamenController extends Controller
{
    protected $gemini;
    protected $parser;

    public function __construct(GeminiService $gemini, NoteParserService $parser)
    {
        $this->gemini = $gemini;
        $this->parser = $parser;
    }

    public function scan(Request $request)
    {
        set_time_limit(120);
        try {
            $request->validate([
                'unite_id'             => 'required|exists:unites,id',
                'bloc'                 => 'required|integer|in:1,2',
                'type'                 => 'required|in:theorique,pratique',
                'semestre'             => 'required|integer|in:1,2',
                'annee_academique_id'  => 'required|exists:annees_academiques,id',
                'filiere_id'           => 'required|exists:filieres,id',
            ]);

            $pdfs = $request->file('pdfs');
            if (!$pdfs) $pdfs = $request->file('pdf');
            if (!$pdfs) $pdfs = $request->file('files');
            if (!$pdfs) {
                return response()->json(['success' => false, 'message' => 'Aucun fichier PDF trouvé'], 422);
            }
            if (!is_array($pdfs)) $pdfs = [$pdfs];

            $base64Pdfs = [];
            foreach ($pdfs as $pdf) {
                $base64Pdfs[] = base64_encode(file_get_contents($pdf->getRealPath()));
            }

            $rawText = $this->gemini->scanExamens($base64Pdfs);
            $extracted = $this->parser->parseExamens($rawText);

            if (empty($extracted)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun résultat extrait. Vérifiez que les PDF sont lisibles.',
                ]);
            }

            // Resolve students by nom_prenom — only current year, same filiere + niveau as the unite
            $unite = Unite::find($request->unite_id);
            $etudiants = Etudiant::where('annee_academique_id', $request->annee_academique_id)
                ->whereHas('groupe.niveau.filiere', function ($q) use ($request) {
                    $q->where('id', $request->filiere_id);
                })
                ->whereHas('groupe.niveau', function ($q) use ($unite) {
                    $q->where('numero', $unite->numero_annee);
                })
                ->get()->keyBy(function ($e) {
                    return strtolower(trim(preg_replace('/\s+/', ' ', $e->nom_prenom)));
                });

            $results = [];
            $errors = [];
            foreach ($extracted as $item) {
                $nom = $item['nom_prenom'] ?? '';
                $code = $item['code'] ?? '';
                $note = $item['note'] ?? null;

                if (empty($nom)) {
                    $errors[] = ['code' => $code, 'message' => 'Nom manquant'];
                    continue;
                }

                $key = strtolower(trim(preg_replace('/\s+/', ' ', $nom)));
                $etudiant = $etudiants[$key] ?? null;

                // Try partial matching if exact fails
                if (!$etudiant) {
                    $words = explode(' ', $key);
                    foreach ($etudiants as $ek => $ev) {
                        $match = true;
                        foreach ($words as $w) {
                            if (strlen($w) > 2 && strpos($ek, $w) === false) {
                                $match = false;
                                break;
                            }
                        }
                        if ($match) { $etudiant = $ev; break; }
                    }
                }

                $results[] = [
                    'code'               => $code,
                    'nom_prenom'         => $nom,
                    'cin'                => $item['cin'] ?? '',
                    'note'               => $note !== null ? (float) $note : null,
                    'etudiant_id'        => $etudiant?->id,
                    'numero_inscription' => $etudiant?->numero_inscription ?? '',
                    'groupe'             => $etudiant?->groupe?->nom ?? '',
                    'found'              => $etudiant !== null,
                ];
            }

            return response()->json([
                'success' => true,
                'data'    => ['resultats' => $results, 'erreurs' => $errors],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur scan examen: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan: ' . $e->getMessage()], 500);
        }
    }

    public function searchEtudiants(Request $request)
    {
        $request->validate([
            'search'               => 'required|string|min:1',
            'annee_academique_id'  => 'required|exists:annees_academiques,id',
            'filiere_id'           => 'required|exists:filieres,id',
            'niveau_numero'        => 'nullable|integer',
        ]);

        $query = Etudiant::where('annee_academique_id', $request->annee_academique_id)
            ->whereHas('groupe.niveau.filiere', function ($q) use ($request) {
                $q->where('id', $request->filiere_id);
            });

        if ($request->niveau_numero) {
            $query->whereHas('groupe.niveau', function ($q) use ($request) {
                $q->where('numero', $request->niveau_numero);
            });
        }

        $s = '%' . $request->search . '%';
        $query->where(function ($q) use ($s) {
            $q->where('nom_prenom', 'LIKE', $s)
              ->orWhere('numero_inscription', 'LIKE', $s)
              ->orWhere('cin', 'LIKE', $s);
        });

        $etudiants = $query->with('groupe:id,nom')->limit(15)->get();

        return response()->json([
            'success' => true,
            'data'    => $etudiants->map(function ($e) {
                return [
                    'id'                 => $e->id,
                    'nom_prenom'         => $e->nom_prenom,
                    'cin'                => $e->cin,
                    'numero_inscription' => $e->numero_inscription,
                    'groupe'             => $e->groupe?->nom ?? '',
                ];
            }),
        ]);
    }

    public function confirm(Request $request)
    {
        try {
            $request->validate([
                'resultats'            => 'required|array',
                'resultats.*.etudiant_id' => 'required|exists:etudiants,id',
                'resultats.*.note'     => 'nullable|numeric|min:0|max:20',
                'unite_id'             => 'required|exists:unites,id',
                'bloc'                 => 'required|integer|in:1,2',
                'type'                 => 'required|in:theorique,pratique',
                'semestre'             => 'required|integer|in:1,2',
                'annee_academique_id'  => 'required|exists:annees_academiques,id',
            ]);

            $admin = Auth::user();
            $saved = 0;

            foreach ($request->resultats as $item) {
                if ($item['note'] === null || $item['note'] === '') continue;

                Examen::updateOrCreate(
                    [
                        'etudiant_id'        => $item['etudiant_id'],
                        'unite_id'           => $request->unite_id,
                        'bloc'               => $request->bloc,
                        'type'               => $request->type,
                        'semestre'           => $request->semestre,
                        'annee_academique_id' => $request->annee_academique_id,
                    ],
                    ['valeur' => $item['note']]
                );
                $saved++;
            }

            ActivityLog::create([
                'admin_id'    => $admin->id,
                'admin_name'  => $admin->name,
                'action_type' => 'update',
                'description' => "{$admin->name} a scanne {$saved} note(s) d'examen {$request->type} bloc {$request->bloc}",
                'model_type'  => 'App\Models\Examen',
            ]);

            return response()->json([
                'success' => true,
                'message' => "{$saved} note(s) d'examen enregistree(s)",
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur confirmation scan examen: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'enregistrement'], 500);
        }
    }
}
