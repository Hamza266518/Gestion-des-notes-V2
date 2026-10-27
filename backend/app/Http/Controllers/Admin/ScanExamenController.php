<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\GeminiTransportException;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Unite;
use App\Models\ActivityLog;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\PublicationLockService;
use App\Services\ScanEtudiantMatcher;
use App\Traits\ValidatesScanUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScanExamenController extends Controller
{
    use ValidatesScanUploads;

    protected $gemini;
    protected $parser;
    protected $locks;
    protected $matcher;

    public function __construct(
        GeminiService $gemini,
        NoteParserService $parser,
        PublicationLockService $locks,
        ScanEtudiantMatcher $matcher
    ) {
        $this->gemini = $gemini;
        $this->parser = $parser;
        $this->locks = $locks;
        $this->matcher = $matcher;
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
                'groupe_id'            => 'nullable|integer',
            ]);

            $pdfs = $request->file('pdfs');
            if (!$pdfs) $pdfs = $request->file('pdf');
            if (!$pdfs) $pdfs = $request->file('files');
            if (!$pdfs) {
                return response()->json(['success' => false, 'message' => 'Aucun fichier PDF trouvé'], 422);
            }
            if (!is_array($pdfs)) $pdfs = [$pdfs];

            $invalid = $this->validateScanUploads($pdfs, ['pdf']);
            if ($invalid !== null) {
                return $invalid;
            }

            $base64Pdfs = [];
            foreach ($pdfs as $pdf) {
                $base64Pdfs[] = base64_encode(file_get_contents($pdf->getRealPath()));
            }

            // Told to the AI so it reads the right exam column and scale.
            $unite   = Unite::find($request->unite_id);
            $contexte = $this->contexteScanExamens($request, $unite);

            try {
                $rawText = $this->gemini->scanExamens($base64Pdfs, $contexte);
            } catch (GeminiTransportException $e) {
                return response()->json([
                    'success' => false,
                    'message' => "Connexion sécurisée à l'API Gemini impossible. Vérifiez le certificat CA (CA_BUNDLE_PATH) et la clé API.",
                ], 502);
            }

            $extracted = $this->parser->parseExamens($rawText);
            if (empty($extracted)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun résultat extrait. Vérifiez que les PDF sont lisibles.',
                ]);
            }

            // The sheet is read by NAME against the list of the unite's
            // filière/année (narrowed to the chosen groupe when one was picked),
            // exactly like the contrôle scanner: no anonymous codes, no key sheet.
            $groupeIds = $this->groupeIdsDuPerimetre($request);
            if ($groupeIds->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun groupe trouvé pour cette filière/année. Vérifiez que l\'année académique est correcte.',
                ]);
            }

            $results = [];
            $errors = [];
            foreach ($extracted as $item) {
                $geminiNom = (string) ($item['nom_prenom'] ?? $item['nom'] ?? '');
                $geminiNomAr = (string) ($item['nom_ar'] ?? '');
                $numeroScanne = (string) ($item['numero_inscription'] ?? '');

                $etudiant = $this->matcher->match(
                    $groupeIds,
                    (int) $request->annee_academique_id,
                    $geminiNom,
                    $numeroScanne !== '' ? $numeroScanne : null
                );

                $parsedValue = $this->parser->parseValue($item['note'] ?? null);

                // Keyed on the same columns as the examens UNIQUE index, which
                // excludes the academic year: a redoublant's row is reused, not a
                // second row inserted, so the year must not enter the match.
                $already = $etudiant !== null && Examen::where([
                    'etudiant_id' => $etudiant->id,
                    'unite_id'    => $request->unite_id,
                    'bloc'        => (int) $request->bloc,
                    'type'        => $request->type,
                    'semestre'    => (int) $request->semestre,
                ])->exists();

                $results[] = [
                    'nom_prenom'         => $etudiant?->nom_prenom ?? $geminiNom,
                    'nom_ar'             => $etudiant?->nom_ar ?? $geminiNomAr,
                    'numero_inscription' => $etudiant?->numero_inscription ?? '',
                    'groupe'             => $etudiant?->groupe?->nom ?? '',
                    'groupe_id'          => $etudiant?->groupe_id,
                    'note'               => $parsedValue['valeur'],
                    'est_absent'         => $parsedValue['est_absent'],
                    'raw_note'           => is_scalar($item['note'] ?? null) ? (string) $item['note'] : null,
                    'note_invalide'      => $parsedValue['invalid'],
                    'etudiant_id'        => $etudiant?->id,
                    'verrouille'         => $etudiant !== null
                        && $this->locks->semestreVerrouille(
                            (int) $etudiant->groupe_id,
                            (int) $request->annee_academique_id,
                            (int) $request->semestre
                        ),
                    'already_confirmed'  => $already,
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

    /**
     * Description of the exam being scanned, handed to the AI so it reads the
     * right exam column and knows the mark scale instead of guessing.
     */
    private function contexteScanExamens(Request $request, ?Unite $unite): string
    {
        $lignes = [];

        $lignes[] = 'Unité: ' . ($unite?->nom ?: ('#' . $request->unite_id));
        $lignes[] = 'Semestre: S' . $request->semestre . ' — Bloc: ' . $request->bloc
            . ' — Type: ' . ($request->type === 'pratique' ? 'pratique' : 'théorique');
        $lignes[] = 'La note de cet examen est sur 20 (quart de point: 0,25 / 0,50 / 0,75).';

        if ($request->filled('groupe_id')) {
            $groupe = Groupe::find((int) $request->groupe_id);
            if ($groupe) {
                $lignes[] = 'Groupe concerné: ' . $groupe->nom;
            }
        }

        $lignes[] = 'La feuille ne concerne que cet examen: lire la colonne unique de notes (ignorer toute autre colonne du même formulaire).';

        return implode("\n", $lignes);
    }

    /**
     * Groupe ids the scanned sheet may be matched against: the unite's
     * filière/année, restricted to the requested academic year, narrowed to the
     * chosen groupe when one was provided.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function groupeIdsDuPerimetre(Request $request): \Illuminate\Support\Collection
    {
        $unite = Unite::find($request->unite_id);
        if (!$unite) return collect();

        if ($request->filled('groupe_id')) {
            $groupe = Groupe::where('id', (int) $request->groupe_id)
                ->where('annee_academique_id', (int) $request->annee_academique_id);
            $niveau = Niveau::where('filiere_id', $unite->filiere_id)
                ->where('numero', $unite->numero_annee)
                ->first();
            if (!$niveau) return collect();
            $groupe = $groupe->where('niveau_id', $niveau->id)->first();
            return $groupe ? collect([(int) $groupe->id]) : collect();
        }

        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) return collect();

        return Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', (int) $request->annee_academique_id)
            ->orderBy('nom')
            ->pluck('id');
    }

    public function searchEtudiants(Request $request)
    {
        $request->validate([
            'search'               => 'required|string|min:1',
            'annee_academique_id'  => 'required|exists:annees_academiques,id',
            'filiere_id'           => 'required|exists:filieres,id',
            'niveau_numero'        => 'nullable|integer',
            'semestre'             => 'nullable|integer|in:1,2',
            'groupe_id'            => 'nullable|integer',
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

        if ($request->groupe_id) {
            $query->where('groupe_id', (int) $request->groupe_id);
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
            'data'    => $etudiants->map(function ($e) use ($request) {
                return [
                    'id'                 => $e->id,
                    'nom_prenom'         => $e->nom_prenom,
                    'cin'                => $e->cin,
                    'numero_inscription' => $e->numero_inscription,
                    'groupe'             => $e->groupe?->nom ?? '',
                    'groupe_id'          => $e->groupe_id,
                    // Lets the manual student picker show the same lock as a
                    // scanned row, so a published group is refused whichever
                    // way the student was matched.
                    'verrouille'         => $this->locks->semestreVerrouille(
                        (int) $e->groupe_id,
                        (int) $request->annee_academique_id,
                        (int) ($request->semestre ?? 1)
                    ),
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
                'resultats.*.note'     => 'nullable|numeric|min:0',
                'resultats.*.est_absent' => 'nullable|boolean',
                'unite_id'             => 'required|exists:unites,id',
                'bloc'                 => 'required|integer|in:1,2',
                'type'                 => 'required|in:theorique,pratique',
                'semestre'             => 'required|integer|in:1,2',
                'annee_academique_id'  => 'required|exists:annees_academiques,id',
                'groupe_id'            => 'nullable|integer',
            ]);

            // Without this the scan is a way around the publication lock: it
            // writes the same Examen rows as the manual grid, admin-only but
            // still unconstrained. Checked before any write.
            $etudiantIds = collect($request->resultats)->pluck('etudiant_id')->map(fn ($id) => (int) $id);
            $groupeIds = Etudiant::whereIn('id', $etudiantIds)->pluck('groupe_id')
                ->map(fn ($id) => (int) $id)->all();

            if ($this->locks->unGroupeVerrouille(
                $groupeIds,
                (int) $request->annee_academique_id,
                (int) $request->semestre
            )) {
                return response()->json([
                    'success' => false,
                    'message' => 'Enregistrement impossible : les résultats de ce groupe sont publiés.',
                ], 403);
            }

            // When the scan was narrowed to a single group, confirm enforces the
            // same cohort: a student linked by hand from the search box belongs
            // to the same list or the row is refused, never saved onto someone
            // else's name.
            if ($request->filled('groupe_id')) {
                $groupeId = (int) $request->groupe_id;
                $horsListe = Etudiant::whereIn('id', $etudiantIds)
                    ->where(function ($q) use ($groupeId, $request) {
                        $q->where('groupe_id', '!=', $groupeId)
                          ->orWhere('annee_academique_id', '!=', (int) $request->annee_academique_id);
                    })
                    ->count();

                if ($horsListe > 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Un ou plusieurs étudiants ne font pas partie de la liste scannée. Retirez-les avant d\'enregistrer.',
                    ], 422);
                }
            }

            $admin = Auth::user();
            $saved = 0;
            $skipped = [];

            // Resolved before the transaction so the journal entry can name the
            // unit and the groups it touched.
            $unite = Unite::find($request->unite_id);
            $groupesScan = Etudiant::with('groupe.niveau.filiere')
                ->whereIn('id', $etudiantIds->unique()->all())
                ->get()
                ->pluck('groupe');

            // All-or-nothing: a row raising a duplicate-key error used to abort
            // the loop after some rows were already committed.
            DB::transaction(function () use ($request, $admin, $unite, $groupesScan, &$saved, &$skipped) {
                foreach ($request->resultats as $item) {
                    $parsed = $this->parser->parseValue($item['note'] ?? null);
                    // An "AB" read next to a numeric field is still an absence:
                    // the parser's own flag wins over the client's default false.
                    $estAbsent = (bool) ($item['est_absent'] ?? false) || $parsed['est_absent'];
                    $valeur = $estAbsent ? 0.0 : $parsed['valeur'];

                    if (!$estAbsent && $valeur === null) {
                        // A numeric value that is not a quarter step (14,7) is a
                        // different problem from a blank cell, so it is named
                        // explicitly rather than dropped without a word.
                        $brut = $item['note'] ?? null;
                        $brut = is_scalar($brut) ? trim(str_replace(',', '.', (string) $brut)) : '';
                        $skipped[] = [
                            'etudiant_id' => $item['etudiant_id'],
                            'raison'      => ($brut !== '' && is_numeric($brut) && !$this->parser->isValidQuarterStep((float) $brut))
                                ? 'note non multiple de 0,25 (valeurs acceptées : 0,25 / 0,50 / 0,75)'
                                : 'note illisible ou absente de la feuille',
                        ];
                        continue;
                    }

                    // Match on the examens UNIQUE key, which excludes the academic
                    // year: a redoublant already owns the row, so it is updated in
                    // place with the new year instead of a duplicate-key 500.
                    Examen::updateOrCreate(
                        [
                            'etudiant_id' => $item['etudiant_id'],
                            'unite_id'    => $request->unite_id,
                            'bloc'        => $request->bloc,
                            'type'        => $request->type,
                            'semestre'    => $request->semestre,
                        ],
                        [
                            'valeur'              => $valeur,
                            'est_absent'          => $estAbsent,
                            'annee_academique_id' => $request->annee_academique_id,
                        ]
                    );
                    $saved++;
                }

                $type = $request->type === 'theorique' ? 'théorique' : 'pratique';
                $description = "a scanné {$saved} note(s) d'examen {$type} — Bloc {$request->bloc}, semestre {$request->semestre}";
                if ($unite) {
                    $description .= " — Unité « {$unite->nom} »";
                }
                $groupesLabel = ActivityLog::groupesLabel($groupesScan);
                if ($groupesLabel !== '') {
                    $description .= " — {$groupesLabel}";
                }

                ActivityLog::create([
                    'admin_id'    => $admin->id,
                    'admin_name'  => $admin->name,
                    'action_type' => 'update',
                    'description' => $description,
                    'model_type'  => 'App\Models\Examen',
                    'model_id'    => $request->unite_id,
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => "{$saved} note(s) d'examen enregistree(s)",
                'skipped' => $skipped,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur confirmation scan examen: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'enregistrement'], 500);
        }
    }
}
