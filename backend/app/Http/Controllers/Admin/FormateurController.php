<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\FormateursImport;
use App\Models\Formateur;
use App\Models\Sequence;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;

class FormateurController extends Controller
{
    protected $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    public function index()
    {
        try {
            $formateurs = Formateur::with(['user', 'sequences.unite.filiere'])->get();
            return response()->json(['success' => true, 'data' => $formateurs]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des formateurs'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'name'     => 'required|string',
                'email'    => 'required|email|unique:users,email',
                'password' => 'required|string|min:6',
            ]);

            $user = User::create([
                'name'               => $request->name,
                'email'              => $request->email,
                'password'           => Hash::make($request->password),
                'password_encrypted' => Crypt::encryptString($request->password),
                'password_original_encrypted' => Crypt::encryptString($request->password),
                'role'               => 'formateur',
            ]);

            $formateur = Formateur::create(['user_id' => $user->id]);
            $formateur->load('user');

            return response()->json([
                'success' => true,
                'data'    => $formateur,
                'message' => 'Formateur créé',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création du formateur'], 500);
        }
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls',
            ]);

            $import = new FormateursImport();
            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'data'    => [
                    'crees'      => $import->created,
                    'mis_a_jour' => $import->updated,
                    'erreurs'    => $import->errors,
                ],
                'message' => $import->created . ' formateurs importés',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'import des formateurs'], 500);
        }
    }

    public function preview(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls',
            ]);

            $rows = Excel::toArray([], $request->file('file'));
            $data = array_slice($rows[0], 1, 5);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la prévisualisation'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $formateur = Formateur::findOrFail($id);
            $formateur->user()->delete();
            $formateur->delete();
            return response()->json(['success' => true, 'message' => 'Formateur supprimé']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression du formateur'], 500);
        }
    }

    public function getSequences($id)
    {
        try {
            $formateur = Formateur::with(['sequences.unite.filiere'])->findOrFail($id);

            $grouped = $formateur->sequences
                ->groupBy('unite.filiere.nom')
                ->map(function ($sequences, $filiere) {
                    return [
                        'filiere' => $filiere,
                        'unites' => $sequences->groupBy('unite.nom')->map(function ($seqs, $unite) {
                            return [
                                'unite' => $unite,
                                'sequences' => $seqs->map(function ($seq) {
                                    return [
                                            'id' => $seq->id,
                                            'nom' => $seq->nom,
                                            'masse_horaire' => $seq->pivot->masse_horaire ?? null,
                                        ];
                                })->values(),
                            ];
                        })->values(),
                    ];
                })->values();

            return response()->json(['success' => true, 'data' => $grouped]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des séquences'], 500);
        }
    }

    public function assignSequence(Request $request, $id)
    {
        try {
            $request->validate([
                'sequence_id' => 'required|exists:sequences,id',
                'masse_horaire' => 'nullable|integer|min:0',
            ]);

            $formateur = Formateur::findOrFail($id);
            $formateur->sequences()->syncWithoutDetaching([
                $request->sequence_id => ['masse_horaire' => $request->masse_horaire],
            ]);

            return response()->json(['success' => true, 'message' => 'Séquence assignée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'assignation'], 500);
        }
    }

    public function removeSequence(Request $request, $id)
    {
        try {
            $request->validate([
                'sequence_id' => 'required|exists:sequences,id',
            ]);

            $formateur = Formateur::findOrFail($id);
            $formateur->sequences()->detach($request->sequence_id);

            return response()->json(['success' => true, 'message' => 'Séquence retirée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du retrait'], 500);
        }
    }

    public function importSequences(Request $request, $id)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls',
            ]);

            $rows = Excel::toArray([], $request->file('file'))[0] ?? [];
            $header = array_map('strtolower', array_map('trim', $rows[0] ?? []));
            $nomIndex = array_search('sequence_nom', $header);
            $masseIndex = array_search('masse_horaire', $header);

            $assigned = [];
            $erreurs = [];

            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $nom = trim($row[$nomIndex] ?? '');
                if (!$nom) continue;

                $sequence = Sequence::whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($nom) . '%'])->first();

                if (!$sequence) {
                    $erreurs[] = 'Séquence non trouvée: ' . $nom;
                    continue;
                }

                $masse = $masseIndex !== false ? ($row[$masseIndex] ?? null) : null;
                $formateur = Formateur::findOrFail($id);
                $formateur->sequences()->syncWithoutDetaching([
                    $sequence->id => ['masse_horaire' => $masse],
                ]);
                $assigned[] = $nom;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'assignees' => $assigned,
                    'erreurs'   => $erreurs,
                ],
                'message' => count($assigned) . ' séquence(s) assignée(s)',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'import'], 500);
        }
    }

    public function scanSequences(Request $request, $id)
    {
        try {
            $request->validate([
                'image' => 'required|image',
            ]);

            $formateur = Formateur::findOrFail($id);
            $base64  = base64_encode(file_get_contents($request->file('image')->getRealPath()));

            // Get formateur name for the prompt
            $formateurName = $formateur->user->name;
            $prompt  = "This is the official pedagogical tracking document (Cahier de Suivi Pedagogique) from a Moroccan nursing school. " .
                      "It shows a table with formateur names and the sequences or modules they teach. " .
                      "Find the formateur named {$formateurName} and extract all the sequence names listed under their name. " .
                      "Return ONLY a JSON array of sequence names exactly as written in the document. " .
                      "Example: [\"Hygiène individuelle et collective\", \"Technique de la litterie\", \"Anatomie physiologie\"] " .
                      "Return nothing else, no explanation, no markdown.";

            $rawText = $this->gemini->scanFormateurSequences($base64, $prompt);
            $clean   = preg_replace('/```json|```/', '', $rawText);
            $noms    = json_decode(trim($clean), true) ?? [];

            $assigned = [];
            $erreurs   = [];

            foreach ($noms as $nom) {
                $sequence = Sequence::whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($nom) . '%'])->first();

                if (!$sequence) {
                    $erreurs[] = 'Séquence non trouvée: ' . $nom;
                    continue;
                }

                $formateur->sequences()->syncWithoutDetaching([$sequence->id]);
                $assigned[] = $sequence->nom;
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'assignees' => $assigned,
                    'erreurs'   => $erreurs,
                ],
                'message' => count($assigned) . ' séquence(s) assignée(s)',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan'], 500);
        }
    }

    public function updatePassword(Request $request, $id)
    {
        try {
            $request->validate([
                'password' => 'required|string|min:6',
            ]);

            $formateur = Formateur::with('user')->findOrFail($id);
            $formateur->user->update([
                'password'           => Hash::make($request->password),
                'password_encrypted' => Crypt::encryptString($request->password),
            ]);

            return response()->json([
                'success' => true,
                'data'    => $formateur,
                'message' => 'Mot de passe mis à jour',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour du mot de passe'], 500);
        }
    }
}
