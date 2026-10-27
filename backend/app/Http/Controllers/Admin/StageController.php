<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\StageEvaluation;
use App\Models\StageNote;
use App\Models\AnneeAcademique;
use App\Models\Unite;
use App\Services\PublicationLockService;
use App\Services\StageGrilleDocxService;
use App\Services\StageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class StageController extends Controller
{
    public function __construct(
        private StageService $stageService,
        private PublicationLockService $locks
    ) {}

    public function searchStudents(Request $request): JsonResponse
    {
        $request->validate(['search' => 'required|string|min:1']);

        $search = $request->search;

        $currentAnneeId = AnneeAcademique::where('is_current', true)->first()?->id;
        $etudiants = Etudiant::with(['groupe.niveau.filiere', 'anneeAcademique'])
            ->where(function ($q) use ($search) {
                $q->where('nom_prenom', 'like', "%{$search}%")
                  ->orWhere('cin', 'like', "%{$search}%")
                  ->orWhere('numero_inscription', 'like', "%{$search}%");
            })
            ->when(
                $currentAnneeId,
                fn($q) => $q->where('annee_academique_id', $currentAnneeId),
                // Fail closed when no year is current rather than searching all years.
                fn($q) => $q->whereRaw('0 = 1')
            )
            ->limit(20)
            ->get()
            ->map(fn($e) => [
                'id' => $e->id,
                'nom_prenom' => $e->nom_prenom,
                'cin' => $e->cin,
                'numero_inscription' => $e->numero_inscription,
                'filiere' => $e->groupe?->niveau?->filiere?->nom ?? '',
                'section' => $e->groupe?->niveau?->filiere?->section ?? '',
                'type_formation' => $e->groupe?->niveau?->filiere?->type_formation ?? '',
                'niveau_numero' => $e->groupe?->niveau?->numero ?? '',
                'groupe_nom' => $e->groupe?->nom ?? '',
                'annee_label' => $e->anneeAcademique?->label ?? '',
            ]);

        return response()->json(['success' => true, 'data' => $etudiants]);
    }

    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'type' => 'required|in:garde_nuit,grille_evaluation,grille_examen',
            'data' => 'required|array',
        ]);

        $evaluation = StageEvaluation::updateOrCreate(
            [
                'etudiant_id' => $request->etudiant_id,
                'annee_academique_id' => $request->annee_academique_id,
                'type' => $request->type,
            ],
            ['data' => $request->data]
        );

        // Push the grids into the Stage unit's controls so the official
        // coefficient 4 counts in the averages. No mark is written for a grid
        // that is still empty.
        $etudiant = Etudiant::with('groupe.niveau.filiere')->find($request->etudiant_id);
        $sync = ['synced' => [], 'message' => ''];
        if ($etudiant) {
            $sync = $this->stageService->syncForEtudiant($etudiant, (int) $request->annee_academique_id);
        }

        $typeLabels = [
            'garde_nuit'       => 'la garde de nuit',
            'grille_evaluation'=> "la grille d'évaluation",
            'grille_examen'    => "la grille d'examen",
        ];
        if ($etudiant) {
            $description = 'a enregistré ' . ($typeLabels[$request->type] ?? 'une grille de stage')
                . " — {$etudiant->nom_prenom}";
            $label = ActivityLog::groupeLabel($etudiant->groupe);
            if ($label !== '') {
                $description .= " — {$label}";
            }
            ActivityLog::create([
                'admin_id'    => Auth::id(),
                'admin_name'  => Auth::user()?->name ?? 'Admin',
                'action_type' => 'update',
                'description' => $description,
                'model_type'  => 'App\Models\StageEvaluation',
                'model_id'    => $evaluation->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'data'    => $evaluation,
            'moyennes'  => $sync['synced'],
            'message'   => $sync['message'],
        ]);
    }

    public function load(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'type' => 'required|in:garde_nuit,grille_evaluation,grille_examen',
        ]);

        $evaluation = StageEvaluation::where([
            'etudiant_id' => $request->etudiant_id,
            'annee_academique_id' => $request->annee_academique_id,
            'type' => $request->type,
        ])->first();

        $etudiant = Etudiant::with('groupe.niveau')->find($request->etudiant_id);

        return response()->json([
            'success' => true,
            'data' => $evaluation ? $evaluation->data : null,
            'moyennes' => $etudiant
                ? $this->stageService->syncedMarksForEtudiant($etudiant, (int) $request->annee_academique_id)
                : [],
        ]);
    }

    /**
     * The "services" a Stage grille can be attached to: they are the Stage
     * unit's own controls, one per department, named by the admin.
     */
    public function stageServices(Request $request): JsonResponse
    {
        $request->validate(['etudiant_id' => 'required|exists:etudiants,id']);

        $etudiant = Etudiant::with('groupe.niveau')->find($request->etudiant_id);
        $niveau = $etudiant?->groupe?->niveau;

        if (!$niveau) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $uniteIds = Unite::where('nom', 'Stage')
            ->where('filiere_id', $niveau->filiere_id)
            ->where('numero_annee', $niveau->numero)
            ->where('is_active', true)
            ->pluck('id');

        $controles = Controle::whereIn('unite_id', $uniteIds)
            ->orderBy('numero')
            ->get(['id', 'numero', 'nom']);

        return response()->json(['success' => true, 'data' => $controles]);
    }

    /**
     * The Examen mark of every service for the students of a group.
     *
     * These are the per-service values the "Saisie examens" screen shows in one
     * column per service; they live in `stage_notes` (the Évaluation side stays
     * in `notes`).
     *
     * @return JsonResponse
     */
    public function notes(Request $request): JsonResponse
    {
        $request->validate([
            'unite_id'  => 'required|exists:unites,id',
            'groupe_id' => 'required|exists:groupes,id',
        ]);

        $controleIds = Controle::where('unite_id', $request->unite_id)->pluck('id');
        $etudiantIds = Etudiant::where('groupe_id', $request->groupe_id)->pluck('id');

        $notes = StageNote::whereIn('controle_id', $controleIds)
            ->whereIn('etudiant_id', $etudiantIds)
            ->where('type', StageNote::EXAMEN_TYPE)
            ->get(['etudiant_id', 'controle_id', 'valeur', 'est_absent']);

        return response()->json(['success' => true, 'data' => $notes]);
    }

    /**
     * Type (or clear) one service's Examen mark.
     *
     * The Examen grid still writes this row on save; typing it here is the same
     * fallback the Évaluation side offers, and a later grid save replaces it,
     * exactly like the Évaluation notes.
     *
     * @return JsonResponse
     */
    public function saveNote(Request $request): JsonResponse
    {
        $estAbsent = $request->boolean('est_absent');

        $request->validate([
            'etudiant_id'         => 'required|exists:etudiants,id',
            'controle_id'         => 'required|exists:controles,id',
            'valeur'              => [$estAbsent ? 'nullable' : 'required', 'numeric', 'min:0', 'max:20'],
            'est_absent'          => 'nullable|boolean',
            'annee_academique_id' => 'nullable|exists:annees_academiques,id',
        ]);

        $controle = Controle::with('unite')->findOrFail($request->controle_id);
        if ($controle->unite?->nom !== 'Stage') {
            return response()->json([
                'success' => false,
                'message' => 'Ce contrôle ne fait pas partie d\'une unité de stage.',
            ], 422);
        }

        $etudiant = Etudiant::find($request->etudiant_id);
        $anneeId = $request->annee_academique_id ?: $etudiant?->annee_academique_id;

        if ($etudiant && $this->locks->semestreVerrouille(
            (int) $etudiant->groupe_id,
            (int) $anneeId,
            (int) $controle->unite?->semestre
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une note publiée',
            ], 403);
        }

        $note = StageNote::updateOrCreate(
            [
                'etudiant_id' => $request->etudiant_id,
                'controle_id' => $request->controle_id,
                'type'        => StageNote::EXAMEN_TYPE,
            ],
            [
                'valeur'     => $estAbsent ? 0 : $request->valeur,
                'est_absent' => $estAbsent,
            ]
        );

        $unite = $controle->unite;
        $groupeLabel = $etudiant && $etudiant->relationLoaded('groupe') ? ActivityLog::groupeLabel($etudiant->groupe) : ActivityLog::groupeLabel($etudiant?->groupe()->with('niveau.filiere')->first());
        $description = 'a enregistré la note d\'examen de stage — '
            . ($etudiant?->nom_prenom ?? 'un stagiaire')
            . ($etudiant?->numero_inscription ? " ({$etudiant->numero_inscription})" : '');
        if ($groupeLabel !== '') {
            $description .= " — {$groupeLabel}";
        }
        if ($unite?->nom) {
            $description .= " — Unité « {$unite->nom} » (coef. {$unite->coefficient})";
        }
        $description .= ' — Contrôle « ' . ($controle->nom ?: ('C' . $controle->numero)) . ' »';
        $description .= ' : ' . ($estAbsent ? 'absent' : $request->valeur);

        ActivityLog::create([
            'admin_id'    => Auth::id(),
            'admin_name'  => Auth::user()?->name ?? 'Admin',
            'action_type' => 'update',
            'description' => $description,
            'model_type'  => 'App\Models\StageNote',
            'model_id'    => $note->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $note,
            'message' => $estAbsent ? 'Absence d\'examen enregistrée' : 'Note d\'examen enregistrée',
        ]);
    }

    /**
     * The "Grille d'évaluation du stage" / "Grille d'examen final" sheets of one
     * student: one sheet per step of his group's rotation, gathered in one Word
     * file. Each sheet is either blank (identity block only) or filled with the
     * marks saved on the Stage page.
     */
    public function downloadGrille(Request $request)
    {
        try {
            $request->validate([
                'type'              => 'required|in:grille_evaluation,grille_examen',
                'etudiant_id'       => 'required|exists:etudiants,id',
                'mode'              => 'required|in:blank,filled',
                'duree'             => 'nullable|string|max:190',
                'pratique_executee' => 'nullable|string|max:190',
                'service'           => 'nullable|string|max:190',
            ]);

            $etudiant = Etudiant::with(['groupe.niveau.filiere', 'anneeAcademique'])
                ->findOrFail($request->etudiant_id);

            $service = app(StageGrilleDocxService::class);
            $ident = $this->identity($etudiant);

            $steps = $this->studentSteps(
                $etudiant,
                $request->type,
                $request->mode,
                $request->input('duree'),
                $request->input('pratique_executee'),
                $request->input('service')
            );

            $document = $service->buildStudentDocument($request->type, $ident, $steps);

            $filename = $service->buildFilename($request->type, $ident);
            if ($request->filled('service') && is_string($request->service) && trim($request->service) !== '') {
                $filename = $this->safeFilename(trim($request->service) . ' - ' . $ident['nom_prenom']) . '.docx';
            }

            return response()->download($document, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('StageController::downloadGrille error: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération de la grille'], 500);
        }
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('~[/\\\\:*?"<>|]+~', '-', $name);

        return rtrim(trim($name), ' .');
    }

    /**
     * The same sheets for every student of a group, gathered in one zip holding
     * one Word file per student.
     */
    public function downloadGroupe(Request $request)
    {
        try {
            $request->validate([
                'type'              => 'required|in:grille_evaluation,grille_examen',
                'groupe_id'         => 'required|exists:groupes,id',
                'mode'              => 'required|in:blank,filled',
                'duree'             => 'nullable|string|max:190',
                'pratique_executee' => 'nullable|string|max:190',
                'service'           => 'nullable|string|max:190',
            ]);

            $etudiants = Etudiant::with(['groupe.niveau.filiere', 'anneeAcademique'])
                ->where('groupe_id', $request->groupe_id)
                ->orderBy('nom_prenom')
                ->get();

            if ($etudiants->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Aucun étudiant dans ce groupe'], 422);
            }

            $service = app(StageGrilleDocxService::class);
            $files = [];
            $tmp = [];
            $svcFilter = $request->input('service');

            foreach ($etudiants as $etudiant) {
                $ident = $this->identity($etudiant);

                $steps = $this->studentSteps($etudiant, $request->type, $request->mode, $request->input('duree'), $request->input('pratique_executee'), $svcFilter);
                if ($steps === []) {
                    continue;
                }

                $document = $service->buildStudentDocument($request->type, $ident, $steps);

                $filename = $service->buildFilename($request->type, $ident);
                if (is_string($svcFilter) && trim($svcFilter) !== '') {
                    $filename = $this->safeFilename(trim($svcFilter) . ' - ' . $ident['nom_prenom']) . '.docx';
                }

                $files[$filename] = $document;
                $tmp[] = $document;
            }

            if (empty($files)) {
                return response()->json(['success' => false, 'message' => 'Aucune grille à générer pour ce service'], 422);
            }

            $archive = $service->buildArchive($files);

            foreach ($tmp as $path) {
                @unlink($path);
            }

            $first = $etudiants->first();
            $niveau = $this->niveauLabel($this->grilleNiveau($first));
            $baseName = $service->buildGroupFilename($request->type, $niveau, (string) ($first->groupe?->nom ?? ''));

            if (is_string($svcFilter) && trim($svcFilter) !== '') {
                $baseName = str_replace('.zip', ' - ' . $this->safeFilename(trim($svcFilter)) . '.zip', $baseName);
            }

            return response()->download($archive, $baseName, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('StageController::downloadGroupe error: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération des grilles'], 500);
        }
    }

    /**
     * Every sheet of one student: one per step of his group's rotation, in
     * calendar order. A student without a rotation gets a single sheet, as
     * before, so the download never comes back empty.
     *
     * @return array<int, array<string, mixed>>
     */
    private function studentSteps(
        Etudiant $etudiant,
        string $type,
        string $mode,
        ?string $duree,
        ?string $pratique,
        ?string $serviceFilter = null
    ): array {
        $steps = $this->rotationSteps($etudiant);

        if ($steps === []) {
            $steps = [$this->stagePlace($etudiant)];
        }

        $out = [];
        $filter = is_string($serviceFilter) ? mb_strtolower(trim($serviceFilter)) : '';

        foreach ($steps as $step) {
            $serviceDuStage = (string) ($step['service'] ?? '');
            if ($filter !== '' && mb_strtolower(trim($serviceDuStage)) !== $filter) {
                continue;
            }

            $out[] = [
                'duree'    => (string) $duree,
                'lieu'     => (string) ($step['lieu'] ?? ''),
                'service'  => $serviceDuStage,
                'pratique' => (string) $pratique,
                'data'     => $mode === 'filled'
                    ? $this->gridPayload($etudiant, $type, $serviceDuStage)
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Identity block values, worded as on the reference sheets.
     */
    private function identity(Etudiant $etudiant): array
    {
        $niveau = $etudiant->groupe?->niveau;
        $filiere = $niveau?->filiere;
        $niveauLabel = $this->niveauLabel($this->grilleNiveau($etudiant));
        $filiereNom = (string) ($filiere?->nom ?? '');

        return [
            'nom_prenom'         => (string) $etudiant->nom_prenom,
            'numero_inscription' => (string) $etudiant->numero_inscription,
            'section'            => (string) ($filiere?->section ?? ''),
            'filiere'            => $niveauLabel !== '' ? trim($filiereNom . ' (' . $niveauLabel . ')') : $filiereNom,
            'formation'          => (string) ($etudiant->groupe?->promotion ?? ''),
            // The sheets carry the year of the rotation calendar, which is the
            // year of the stage, even when the app's current year is another one.
            'annee_scolaire'     => $this->rotationYear($etudiant)
                ?: (string) ($etudiant->anneeAcademique?->label ?? ''),
        ];
    }

    /**
     * One grid's saved payload: the service's sheet when a service is given,
     * otherwise the first service that has data.
     */
    private function gridPayload(Etudiant $etudiant, string $type, ?string $service): ?array
    {
        $evaluation = StageEvaluation::where('etudiant_id', $etudiant->id)
            ->where('type', $type)
            ->where('annee_academique_id', $etudiant->annee_academique_id)
            ->first();

        // A sheet may have been saved under another year, or under the other
        // grid type: fall back rather than print a blank sheet.
        if (!$evaluation) {
            $evaluation = StageEvaluation::where('etudiant_id', $etudiant->id)
                ->where('type', $type)
                ->latest('updated_at')
                ->first();
        }

        if (!$evaluation) {
            $evaluation = StageEvaluation::where('etudiant_id', $etudiant->id)
                ->latest('updated_at')
                ->first();
        }

        $map = $evaluation?->data;

        if (!is_array($map) || $map === []) {
            return null;
        }

        return $this->resolveGrid($map, $service);
    }

    /**
     * Picks one saved grid out of the {service: grid} map.
     *
     * A legacy sheet stored a single grid at the top level, so the map is
     * returned as-is when it already carries "lignes" (examen) or the
     * behaviour/aptitude marks (évaluation).
     */
    /**
     * Picks one saved grid out of the {service: grid} map.
     *
     * A legacy sheet stored a single grid at the top level, so the map is
     * returned as-is when it already carries "lignes" (examen) or the
     * behaviour/aptitude marks (évaluation).
     */
    private function resolveGrid(array $map, ?string $service): ?array
    {
        if ($this->isGrid($map)) {
            return $map;
        }

        $wanted = $service === null ? '' : mb_strtolower(trim($service));

        if ($wanted !== '') {
            foreach ($map as $key => $grid) {
                if (is_array($grid) && mb_strtolower(trim((string) $key)) === $wanted && $this->isGrid($grid)) {
                    return $grid;
                }
            }

            foreach ($map as $key => $grid) {
                if (is_array($grid) && mb_strtolower(trim((string) $key)) === $wanted) {
                    return $grid;
                }
            }
        }

        $best = null;
        $bestCount = -1;
        foreach ($map as $grid) {
            if (!is_array($grid) || !$this->isGrid($grid)) {
                continue;
            }
            $count = 0;
            if (isset($grid['lignes']) && is_array($grid['lignes'])) {
                foreach ($grid['lignes'] as $l) {
                    if (is_array($l)) {
                        $n = $l['note'] ?? ($l['valeur'] ?? ($l['value'] ?? null));
                        if ($n !== '' && $n !== null) $count++;
                    }
                }
            } elseif (isset($grid['comportement']) && is_array($grid['comportement'])) {
                foreach ($grid['comportement'] as $n) {
                    if ($n !== '' && $n !== null) $count++;
                }
            } elseif (isset($grid['aptitudes']) && is_array($grid['aptitudes'])) {
                foreach ($grid['aptitudes'] as $n) {
                    if ($n !== '' && $n !== null) $count++;
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $grid;
            }
        }
        if ($best !== null) {
            return $best;
        }

        foreach ($map as $grid) {
            if (is_array($grid) && $this->isGrid($grid)) {
                return $grid;
            }
        }

        // A grid saved as a bare list of lines.
        foreach ($map as $grid) {
            if (is_array($grid) && isset($grid[0]) && is_array($grid[0]) && array_key_exists('note', $grid[0])) {
                return ['lignes' => $grid];
            }
        }

        if (isset($map[0]) && is_array($map[0]) && array_key_exists('note', $map[0])) {
            return ['lignes' => $map];
        }

        return null;
    }

    private function isGrid(array $grid): bool
    {
        return (isset($grid['lignes']) && is_array($grid['lignes']))
            || (isset($grid['comportement']) && is_array($grid['comportement']))
            || (isset($grid['aptitudes']) && is_array($grid['aptitudes']));
    }

    public function savedServices(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'type'         => 'nullable|in:grille_evaluation,grille_examen',
        ]);

        $query = StageEvaluation::where('etudiant_id', $request->etudiant_id);
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        $evaluation = $query->latest('updated_at')->first();
        $map = $evaluation?->data;
        $services = [];

        if (is_array($map)) {
            foreach ($map as $key => $grid) {
                if (is_string($key) && is_array($grid)) {
                    $services[] = $key;
                }
            }
            $services = array_values(array_unique($services));
            sort($services, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return response()->json(['success' => true, 'data' => $services]);
    }
    private function niveauLabel(?int $numero): string
    {
        if (!$numero) {
            return '';
        }

        return $numero === 1 ? '1ère année' : $numero . 'ème année';
    }

    /**
     * The level the sheet must carry, which is last year's level plus one when
     * the student is moving up. The database still holds the 2025/2026 class, so
     * a IA 1ère is really entering 2ème and a IP 2ème is entering 3ème, and only
     * that projected level has a rotation on the 2026/2027 calendar. The shift is
     * only applied when the higher level actually exists, so a graduating level
     * is left untouched.
     */
    private function grilleNiveau(Etudiant $etudiant): ?int
    {
        $niveau = $etudiant->groupe?->niveau;
        $numero = $niveau?->numero !== null ? (int) $niveau->numero : null;

        if ($numero === null || $numero === 0) {
            return $numero;
        }

        $filiere = trim((string) ($niveau?->filiere?->nom ?? ''));
        $higher = $numero + 1;

        $path = database_path('data/stage_rotations.json');
        if ($filiere === '' || !is_file($path)) {
            return $numero;
        }

        foreach ((array) json_decode((string) file_get_contents($path), true) as $section) {
            $sameFiliere = mb_strtolower(trim((string) ($section['filiere'] ?? ''))) === mb_strtolower($filiere);

            if ($sameFiliere && (int) ($section['niveau'] ?? 0) === $higher) {
                return $higher;
            }
        }

        return $numero;
    }

    /**
     * The school year the rotation calendar belongs to, so the sheets show the
     * stage's year rather than the app's current one. Empty when the student has
     * no rotation.
     */
    private function rotationYear(Etudiant $etudiant): string
    {
        $niveau = $etudiant->groupe?->niveau;
        $filiere = trim((string) ($niveau?->filiere?->nom ?? ''));
        $numero = $this->grilleNiveau($etudiant) ?? 0;

        if ($filiere === '' || $numero === 0) {
            return '';
        }

        $path = database_path('data/stage_rotations.json');
        if (!is_file($path)) {
            return '';
        }

        $sections = json_decode((string) file_get_contents($path), true);

        foreach ((array) $sections as $section) {
            $sameFiliere = mb_strtolower(trim((string) ($section['filiere'] ?? ''))) === mb_strtolower($filiere);
            if ($sameFiliere && (int) ($section['niveau'] ?? 0) === $numero) {
                return trim((string) ($section['annee'] ?? ''));
            }
        }

        return '';
    }

    /**
     * The ordered steps of this student's group on the rotation calendar: each
     * step is one service at one place. The rotation is attached to the filière
     * and the year, not to the school year, so it answers for the current year
     * too.
     *
     * @return array<int, array{service: string, lieu: string}>
     */
    private function rotationSteps(Etudiant $etudiant): array
    {
        $niveau = $etudiant->groupe?->niveau;
        $filiere = trim((string) ($niveau?->filiere?->nom ?? ''));
        $numero = $this->grilleNiveau($etudiant) ?? 0;
        $groupe = $this->groupeLetter((string) ($etudiant->groupe?->nom ?? ''));

        if ($filiere === '' || $numero === 0 || $groupe === '') {
            return [];
        }

        $path = database_path('data/stage_rotations.json');
        if (!is_file($path)) {
            return [];
        }

        $sections = json_decode((string) file_get_contents($path), true);

        foreach ((array) $sections as $section) {
            $sameFiliere = mb_strtolower(trim((string) ($section['filiere'] ?? ''))) === mb_strtolower($filiere);
            if (!$sameFiliere || (int) ($section['niveau'] ?? 0) !== $numero) {
                continue;
            }

            $steps = (array) (($section['groupes'] ?? [])[$groupe] ?? []);

            return array_values(array_map(fn ($step) => [
                'service' => (string) ($step['service'] ?? ''),
                'lieu'    => (string) ($step['lieu'] ?? ''),
            ], $steps));
        }

        return [];
    }

    /**
     * The place and department the calendar gives this student's group: the
     * calendar is attached to the filière and the year, not to the school year,
     * so it also answers for the current year.
     *
     * @return array{lieu: string, service: string}
     */
    private function stagePlace(Etudiant $etudiant): array
    {
        $empty = ['lieu' => '', 'service' => ''];

        $niveau = $etudiant->groupe?->niveau;
        $filiere = trim((string) ($niveau?->filiere?->nom ?? ''));
        $numero = $this->grilleNiveau($etudiant) ?? 0;
        $groupe = $this->groupeLetter((string) ($etudiant->groupe?->nom ?? ''));

        if ($filiere === '' || $numero === 0 || $groupe === '') {
            return $empty;
        }

        $path = database_path('data/stage_calendrier.json');
        if (!is_file($path)) {
            return $empty;
        }

        $calendar = json_decode((string) file_get_contents($path), true);

        foreach ((array) ($calendar['sections'] ?? []) as $section) {
            $sameFiliere = mb_strtolower(trim((string) ($section['filiere'] ?? ''))) === mb_strtolower($filiere);
            if (!$sameFiliere || (int) ($section['niveau'] ?? 0) !== $numero) {
                continue;
            }

            $entry = (array) ($section['groupes'] ?? [])[$groupe] ?? null;

            if (is_array($entry)) {
                return [
                    'lieu'    => (string) ($entry['lieu'] ?? ''),
                    'service' => (string) ($entry['service'] ?? ''),
                ];
            }

            return $empty;
        }

        return $empty;
    }

    /**
     * "Groupe A" reads as "A" on the calendar.
     */
    private function groupeLetter(string $nom): string
    {
        $nom = trim(preg_replace('/\s+/', ' ', $nom));

        if ($nom === '') {
            return '';
        }

        $parts = explode(' ', $nom);
        $letter = (string) end($parts);

        return ctype_alpha($letter) && strlen($letter) <= 2 ? mb_strtoupper($letter) : '';
    }
}
