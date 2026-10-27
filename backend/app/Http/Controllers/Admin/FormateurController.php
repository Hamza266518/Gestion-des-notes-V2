<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\FormateursImport;
use App\Models\Formateur;
use App\Models\Sequence;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\UsernameGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
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

            // Admin-only: explicitly reveal the current password for the formateur list
            $formateurs->each(fn (Formateur $f) => $f->user?->withPlainPassword());

            return response()->json(['success' => true, 'data' => $formateurs]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des formateurs'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom'      => 'required_without:name|string|nullable',
                'prenom'   => 'required_without:name|string|nullable',
                'name'     => 'required_without:nom|string|nullable',
                'username' => 'nullable|string|max:60|regex:/^[a-z0-9]+(?:\.[a-z0-9]+)*$/',
                'password' => 'nullable|string|min:6',
                'force'    => 'nullable|boolean',
            ], [
                'name.required_without' => 'Le nom est requis',
                'nom.required_without'  => 'Le nom est requis',
                'prenom.required_without' => 'Le prénom est requis',
                'password.min'          => 'Le mot de passe doit contenir au moins 6 caractères',
                'username.regex'        => "L'identifiant ne doit contenir que des lettres minuscules et des points",
                'username.max'          => "L'identifiant ne doit pas dépasser 60 caractères",
            ]);

            $generator = app(UsernameGenerator::class);

            if (filled($request->nom) || filled($request->prenom)) {
                $nom    = mb_strtoupper(trim((string) $request->nom));
                $prenom = mb_convert_case(trim((string) $request->prenom), MB_CASE_TITLE, 'UTF-8');
                $name   = trim($nom . ' ' . $prenom);
            } else {
                $name = trim((string) $request->name);
            }

            if ($name === '') {
                return response()->json(['success' => false, 'message' => 'Le nom est requis'], 422);
            }

            $key = UsernameGenerator::nameKey($name);
            if ($key !== '' && !$request->boolean('force')) {
                $existing = User::where('role', 'formateur')
                    ->get()
                    ->first(function (User $u) use ($key) {
                        return UsernameGenerator::nameKey((string) $u->name) === $key;
                    });
                if ($existing) {
                    return response()->json([
                        'success' => false,
                        'code'    => 'NAME_EXISTS',
                        'message' => "Un formateur portant un nom similaire existe déjà ({$existing->name}). Voulez-vous quand même créer ce formateur ?",
                        'existing' => ['id' => $existing->id, 'name' => $existing->name],
                    ], 409);
                }
            }

            $password = filled($request->password) ? $request->password : User::generateStrongPassword();

            // Left empty, the identifier is generated from the name (e.g. "tazi.karim").
            // The identifier is assigned once. A chosen one that is already taken is
            // disambiguated (sara, sara2, sara3, ...) instead of failing the creation.
            $username = filled($request->username)
                ? $generator->reserve(mb_strtolower(trim($request->username)))
                : $generator->reserveFor($name, 'formateur');

            $user = User::create([
                'name'               => $name,
                'username'           => $username,
                'password'           => Hash::make($password),
                'password_encrypted' => Crypt::encryptString($password),
                'password_original_encrypted' => Crypt::encryptString($password),
                'role'               => 'formateur',
            ]);

            $formateur = Formateur::create(['user_id' => $user->id]);
            $formateur->load('user')->user->withPlainPassword();

            return response()->json([
                'success' => true,
                'data'    => $formateur,
                'message' => 'Formateur créé',
            ]);
        } catch (\Exception $e) {
            if (method_exists($e, 'errors')) {
                return response()->json(['success' => false, 'errors' => $e->errors()], 422);
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => $e->getMessage()], 422);
            }
            \Log::error('FormateurController::store error: ' . $e->getMessage());
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

            // Get every sequence assignment across all formateurs
            $usage = DB::table('formateur_sequences')
                ->join('formateurs', 'formateurs.id', '=', 'formateur_sequences.formateur_id')
                ->join('users', 'users.id', '=', 'formateurs.user_id')
                ->select(
                    'formateur_sequences.sequence_id',
                    'formateur_sequences.formateur_id',
                    'formateur_sequences.masse_horaire',
                    'users.name as formateur_name'
                )
                ->get()
                ->groupBy('sequence_id');

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

            return response()->json(['success' => true, 'data' => $grouped, 'usage' => $usage]);
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
                'force' => 'nullable|boolean',
            ]);

            $formateur = Formateur::findOrFail($id);

            $existing = DB::table('formateur_sequences')
                ->where('sequence_id', $request->sequence_id)
                ->where('formateur_id', '!=', $id)
                ->first();

            if ($existing && !$request->boolean('force')) {
                $owner = DB::table('formateur_sequences')
                    ->join('formateurs', 'formateurs.id', '=', 'formateur_sequences.formateur_id')
                    ->join('users', 'users.id', '=', 'formateurs.user_id')
                    ->where('formateur_sequences.sequence_id', $request->sequence_id)
                    ->where('formateur_sequences.formateur_id', '!=', $id)
                    ->value('users.name');

                return response()->json([
                    'success' => false,
                    'conflict' => true,
                    'owner' => $owner,
                    'message' => "This sequence is already assigned to {$owner}. Do you want to assign it anyway?",
                ], 409);
            }

            $formateur->sequences()->syncWithoutDetaching([
                $request->sequence_id => ['masse_horaire' => $request->masse_horaire],
            ]);

            return response()->json(['success' => true, 'message' => 'Séquence assignée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'assignation'], 500);
        }
    }

    public function updateMasseHoraire(Request $request, $id, $sequenceId)
    {
        try {
            $request->validate([
                'masse_horaire' => 'nullable|integer|min:0',
            ]);

            DB::table('formateur_sequences')
                ->where('formateur_id', $id)
                ->where('sequence_id', $sequenceId)
                ->update([
                    'masse_horaire' => $request->masse_horaire,
                    'updated_at' => now(),
                ]);

            return response()->json(['success' => true, 'message' => 'Masse horaire mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour'], 500);
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
                'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
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
                'name'     => 'nullable|string',
                'nom'      => 'nullable|string',
                'prenom'   => 'nullable|string',
            ], [
                'password.required' => 'Le mot de passe est requis',
                'password.min'      => 'Le mot de passe doit contenir au moins 6 caractères',
            ]);

            $formateur = Formateur::with('user')->findOrFail($id);
            $updates = [
                'password'           => Hash::make($request->password),
                'password_encrypted' => Crypt::encryptString($request->password),
                'password_original_encrypted' => Crypt::encryptString($request->password),
            ];

            if (filled($request->nom) || filled($request->prenom)) {
                $nom    = mb_strtoupper(trim((string) $request->nom));
                $prenom = mb_convert_case(trim((string) $request->prenom), MB_CASE_TITLE, 'UTF-8');
                $name   = trim($nom . ' ' . $prenom);
                if ($name !== '') {
                    $updates['name'] = $name;
                }
            } elseif (filled($request->name)) {
                $updates['name'] = trim((string) $request->name);
            }

            $formateur->user->update($updates);
            $formateur->load('user')->user->withPlainPassword();

            return response()->json([
                'success' => true,
                'data'    => $formateur,
                'message' => 'Informations mises à jour',
            ]);
        } catch (\Exception $e) {
            if (method_exists($e, 'errors')) {
                return response()->json(['success' => false, 'errors' => $e->errors()], 422);
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return response()->json(['success' => false, 'errors' => $e->errors(), 'message' => $e->getMessage()], 422);
            }
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour'], 500);
        }
    }
}
