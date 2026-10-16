<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\GeminiTransportException;
use App\Models\ActivityLog;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Unite;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\PublicationLockService;
use App\Services\ScanEtudiantMatcher;
use App\Services\SoutenanceService;
use App\Traits\ValidatesScanUploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Soutenance (NSTI) marks live as Note rows on the criteria controls of the
 * "Soutenance" unit of a final-year programme. Scanning the jury sheet reads
 * every student line and every criterion grade at once, and the confirmation
 * writes those Note rows -- never Examen rows, which no soutenance reads.
 */
class SoutenanceController extends Controller
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

    /**
     * The active Soutenance unit of a filière/année, criteria loaded, or null
     * when the programme has none.
     */
    private function unitePour(int $filiereId, int $numeroAnnee): ?Unite
    {
        return Unite::where('nom', SoutenanceService::UNITE_NOM)
            ->where('filiere_id', $filiereId)
            ->where('numero_annee', $numeroAnnee)
            ->where('is_active', true)
            ->with('controles')
            ->first();
    }

    /**
     * @return array<int, array{id:int, numero:int, nom:string, note_max:float}>
     */
    private function criteres(Unite $unite): array
    {
        return $unite->controles
            ->sortBy('numero')
            ->values()
            ->map(fn ($c) => [
                'id'       => (int) $c->id,
                'numero'   => (int) $c->numero,
                'nom'      => (string) $c->nom,
                'note_max' => (float) ($c->note_max ?: 20),
            ])
            ->all();
    }

    /**
     * Whether the selected filière/année has a soutenance, its criteria, and
     * its unit, so the page can gate the whole scan on it.
     */
    public function status(Request $request)
    {
        $request->validate([
            'filiere_id' => 'required|exists:filieres,id',
            'niveau_id'  => 'required|exists:niveaux,id',
        ]);

        $niveau = Niveau::find($request->niveau_id);
        if (! $niveau || (int) $niveau->filiere_id !== (int) $request->filiere_id) {
            return response()->json(['success' => false, 'message' => 'Niveau introuvable pour cette filière.'], 422);
        }

        $unite = $this->unitePour((int) $request->filiere_id, (int) $niveau->numero);

        if (! $unite) {
            return response()->json([
                'success' => true,
                'data'    => ['has_soutenance' => false, 'unite' => null, 'criteres' => []],
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'has_soutenance' => true,
                'unite'          => [
                    'id'       => (int) $unite->id,
                    'nom'      => $unite->nom,
                    'semestre' => (int) ($unite->semestre ?: 2),
                ],
                'criteres'       => $this->criteres($unite),
            ],
        ]);
    }

    /**
     * Every filière/année that actually runs a soutenance, with its criteria.
     * The page builds its filters from this list, so a year whose notes are
     * calculated normally can never be picked.
     */
    public function programmes()
    {
        $unites = Unite::where('nom', SoutenanceService::UNITE_NOM)
            ->where('is_active', true)
            ->with(['controles', 'filiere'])
            ->orderBy('filiere_id')
            ->orderBy('numero_annee')
            ->get();

        $programmes = [];
        foreach ($unites as $unite) {
            $niveau = Niveau::where('filiere_id', $unite->filiere_id)
                ->where('numero', $unite->numero_annee)
                ->first();
            if (! $niveau) {
                continue;
            }

            $programmes[] = [
                'filiere_id'     => (int) $unite->filiere_id,
                'filiere_nom'    => (string) ($unite->filiere?->nom ?? ''),
                'niveau_id'      => (int) $niveau->id,
                'niveau_numero'  => (int) $niveau->numero,
                'unite_id'       => (int) $unite->id,
                'unite_semestre' => (int) ($unite->semestre ?: 2),
                'criteres'       => $this->criteres($unite),
            ];
        }

        return response()->json(['success' => true, 'data' => $programmes]);
    }

    public function scan(Request $request)
    {
        set_time_limit(120);
        try {
            $request->validate([
                'unite_id'            => 'required|exists:unites,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'filiere_id'          => 'required|exists:filieres,id',
                'groupe_id'           => 'nullable|integer',
            ]);

            $unite = Unite::with('controles')->find($request->unite_id);
            if (! $unite || $unite->nom !== SoutenanceService::UNITE_NOM || ! $unite->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => "Cette filière/année n'a pas de soutenance.",
                ], 422);
            }

            $criteres = $this->criteres($unite);
            if (count($criteres) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cette soutenance n'a aucun critère à noter.",
                ], 422);
            }

            $pdfs = $request->file('pdfs');
            if (! $pdfs) $pdfs = $request->file('pdf');
            if (! $pdfs) $pdfs = $request->file('files');
            if (! $pdfs) {
                return response()->json(['success' => false, 'message' => 'Aucun fichier PDF trouvé'], 422);
            }
            if (! is_array($pdfs)) $pdfs = [$pdfs];

            $invalid = $this->validateScanUploads($pdfs, ['pdf']);
            if ($invalid !== null) {
                return $invalid;
            }

            $base64Pdfs = [];
            foreach ($pdfs as $pdf) {
                $base64Pdfs[] = base64_encode(file_get_contents($pdf->getRealPath()));
            }

            $groupeIds = $this->groupeIdsDuPerimetre($unite, (int) $request->annee_academique_id, $request->groupe_id);
            if ($groupeIds->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Aucun groupe éligible pour cette soutenance.'], 422);
            }

            try {
                $rawText = $this->gemini->scanSoutenance(
                    $base64Pdfs,
                    $this->contexteScanSoutenance($unite, $criteres)
                );
            } catch (GeminiTransportException $e) {
                return response()->json([
                    'success' => false,
                    'message' => "Connexion sécurisée à l'API Gemini impossible. Vérifiez le certificat CA (CA_BUNDLE_PATH) et la clé API.",
                ], 502);
            }

            $extracted = $this->parser->parseSoutenance($rawText);
            if (empty($extracted)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun résultat extrait. Vérifiez que les PDF sont lisibles.',
                ]);
            }

            $semestre = (int) ($unite->semestre ?: 2);
            $anneeId  = (int) $request->annee_academique_id;

            $results = [];
            foreach ($extracted as $item) {
                $geminiNom    = trim((string) ($item['nom'] ?? ''));
                $geminiNomAr  = trim((string) ($item['nom_ar'] ?? ''));
                $geminiNumero = is_scalar($item['numero_inscription'] ?? null) ? trim((string) $item['numero_inscription']) : '';

                $etudiant = $this->matcher->match(
                    $groupeIds,
                    $anneeId,
                    $geminiNom,
                    $geminiNumero !== '' ? $geminiNumero : null
                );

                $notesBrutes = is_array($item['notes'] ?? null) ? array_values($item['notes']) : [];

                $notes = [];
                foreach ($criteres as $index => $critere) {
                    $raw    = $notesBrutes[$index] ?? null;
                    $parsed = $this->parser->parseValue($raw, (float) $critere['note_max']);

                    $notes[] = [
                        'controle_id' => $critere['id'],
                        'valeur'      => $parsed['valeur'],
                        'est_absent'  => $parsed['est_absent'],
                        'invalid'     => $parsed['invalid'],
                        'raw'         => is_scalar($raw) ? (string) $raw : null,
                    ];
                }

                $results[] = [
                    'etudiant_id'        => $etudiant?->id,
                    'nom_prenom'         => $etudiant?->nom_prenom ?? $geminiNom,
                    'nom_ar'             => $etudiant?->nom_ar ?? $geminiNomAr,
                    'numero_inscription' => $etudiant?->numero_inscription ?? $geminiNumero,
                    'groupe'             => $etudiant?->groupe?->nom ?? '',
                    'groupe_id'          => $etudiant?->groupe_id,
                    'verrouille'         => $etudiant?->groupe_id
                        ? $this->locks->semestreVerrouille((int) $etudiant->groupe_id, $anneeId, $semestre)
                        : false,
                    'notes'              => $notes,
                    'found'              => $etudiant !== null,
                ];
            }

            return response()->json(['success' => true, 'data' => ['resultats' => $results]]);
        } catch (\Exception $e) {
            Log::error('Erreur scan soutenance: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan'], 500);
        }
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'unite_id'            => 'required|exists:unites,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'groupe_id'           => 'nullable|integer',
            'notes'               => 'required|array|max:500',
            'notes.*.etudiant_id' => 'required|exists:etudiants,id',
            'notes.*.controle_id' => 'required|exists:controles,id',
            'notes.*.valeur'      => 'nullable',
        ]);

        try {
            $unite = Unite::with('controles')->findOrFail($request->unite_id);
            if ($unite->nom !== SoutenanceService::UNITE_NOM) {
                return response()->json(['success' => false, 'message' => "Cette unité n'est pas une soutenance."], 422);
            }

            $controles = $unite->controles->keyBy('id');
            $semestre  = (int) ($unite->semestre ?: 2);
            $anneeId   = (int) $request->annee_academique_id;

            $cibleGroupeIds = $this->groupeIdsDuPerimetre($unite, $anneeId, $request->groupe_id);
            if ($cibleGroupeIds->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Aucun groupe éligible pour cette soutenance.'], 422);
            }

            $etudiantIds = collect($request->notes)->pluck('etudiant_id')->unique()->values();
            $etudiants   = Etudiant::whereIn('id', $etudiantIds)->get()->keyBy('id');

            $groupeIds = $etudiants->pluck('groupe_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();
            if ($this->locks->unGroupeVerrouille($groupeIds, $anneeId, $semestre)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Enregistrement impossible : les résultats de ce groupe sont publiés.',
                ], 403);
            }

            $admin   = Auth::user();
            $saved   = 0;
            $skipped = [];

            DB::transaction(function () use ($request, $unite, $controles, $cibleGroupeIds, $etudiants, $admin, $anneeId, $semestre, &$saved, &$skipped) {
                foreach ($request->notes as $item) {
                    $etudiantId = (int) $item['etudiant_id'];
                    $controle   = $controles->get((int) $item['controle_id']);
                    if (! $controle) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => 'critère hors de cette soutenance'];
                        continue;
                    }

                    $etudiant = $etudiants->get($etudiantId);
                    if (! $etudiant) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => 'étudiant introuvable'];
                        continue;
                    }

                    if (! $cibleGroupeIds->contains((int) $etudiant->groupe_id)) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => 'étudiant hors du groupe ciblé'];
                        continue;
                    }

                    if ($this->locks->semestreVerrouille((int) $etudiant->groupe_id, $anneeId, $semestre)) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => 'les notes de ce groupe sont publiées'];
                        continue;
                    }

                    $noteMax = (float) ($controle->note_max ?: 20);
                    $parsed  = $this->parser->parseValue($item['valeur'] ?? null, PHP_FLOAT_MAX);

                    if ($parsed['invalid']) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => 'case vide ou illisible'];
                        continue;
                    }

                    if (! $parsed['est_absent'] && $parsed['valeur'] !== null && $parsed['valeur'] > $noteMax) {
                        $skipped[] = ['etudiant_id' => $etudiantId, 'raison' => "note hors barème (maximum {$noteMax})"];
                        continue;
                    }

                    Note::updateOrCreate(
                        ['etudiant_id' => $etudiantId, 'controle_id' => $controle->id],
                        [
                            'valeur'       => $parsed['est_absent'] ? 0.0 : $parsed['valeur'],
                            'est_absent'   => $parsed['est_absent'],
                            'is_confirmed' => true,
                            'confirmed_at' => now(),
                        ]
                    );
                    $saved++;
                }

                ActivityLog::create([
                    'admin_id'    => $admin?->id,
                    'admin_name'  => $admin?->name,
                    'action_type' => 'update',
                    'description' => ($admin?->name ?? 'Admin') . " a scanné {$saved} note(s) de soutenance ({$unite->nom})",
                    'model_type'  => 'App\Models\Note',
                ]);
            });

            $message = "{$saved} note(s) de soutenance enregistrée(s)";
            if (count($skipped) > 0) {
                $motifs = collect($skipped)->countBy(fn ($s) => $s['raison'] ?? 'raison inconnue');
                $detail = $motifs->map(fn ($n, $r) => "{$n} ({$r})")->implode(' · ');
                $message .= '. ' . count($skipped) . " case(s) ignorée(s) : {$detail}.";
            }

            return response()->json(['success' => true, 'message' => $message, 'skipped' => $skipped]);
        } catch (\Exception $e) {
            Log::error('Erreur confirmation soutenance: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => "Erreur lors de l'enregistrement"], 500);
        }
    }

    /**
     * Groupe ids the scanned sheet may be matched against: the Soutenance
     * unit's filière/année, restricted to the requested academic year, narrowed
     * to the chosen groupe when one was provided.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function groupeIdsDuPerimetre(Unite $unite, int $anneeId, $groupeId): \Illuminate\Support\Collection
    {
        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (! $niveau) return collect();

        return Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $anneeId)
            ->when($groupeId, fn ($q) => $q->where('id', (int) $groupeId))
            ->orderBy('nom')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * Tell the model which criteria to read, in which order, and what the page
     * the sheet comes from looks like.
     *
     * @param array<int, array{nom:string}> $criteres
     */
    private function contexteScanSoutenance(Unite $unite, array $criteres): string
    {
        $lignes   = [];
        $lignes[] = 'Unité: ' . $unite->nom . ' (soutenance / NSTI).';
        $lignes[] = 'Chaque étudiant est noté sur CHAQUE critère ci-dessous, dans cet ordre exact :';
        foreach ($criteres as $i => $critere) {
            $lignes[] = '  ' . ($i + 1) . '. ' . $critere['nom'];
        }
        $lignes[] = 'La feuille peut contenir plusieurs colonnes de critères : lire chaque colonne dans l\'ordre ci-dessus, et renvoyer un tableau "notes" de ' . count($criteres) . ' valeurs par étudiant.';
        $lignes[] = 'Chaque note est sur 20 et se lit par quart de point (0,25).';

        return implode("\n", $lignes);
    }
}
