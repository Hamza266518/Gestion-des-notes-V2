<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\StageEvaluation;
use App\Models\StageNote;
use App\Models\AnneeAcademique;
use App\Models\Unite;
use App\Services\PublicationLockService;
use App\Services\StageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
        $etudiant = Etudiant::with('groupe.niveau')->find($request->etudiant_id);
        $sync = ['synced' => [], 'message' => ''];
        if ($etudiant) {
            $sync = $this->stageService->syncForEtudiant($etudiant, (int) $request->annee_academique_id);
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

        return response()->json([
            'success' => true,
            'data'    => $note,
            'message' => $estAbsent ? 'Absence d\'examen enregistrée' : 'Note d\'examen enregistrée',
        ]);
    }
}
