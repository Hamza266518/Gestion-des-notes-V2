<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\StageEvaluation;
use App\Models\AnneeAcademique;
use App\Services\StageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StageController extends Controller
{
    public function __construct(private StageService $stageService) {}

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
            ->when($currentAnneeId, fn($q) => $q->where('annee_academique_id', $currentAnneeId))
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
}
