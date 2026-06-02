<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AnneeAcademiqueController extends Controller
{
    public function index(): JsonResponse
    {
        $annees = AnneeAcademique::withCount(['groupes', 'etudiants', 'publications', 'diplomes'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $annees,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'label' => [
                'required',
                'string',
                'max:255',
                'unique:annees_academiques',
                'regex:/^\d{4}\/\d{4}$/',
            ],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        // Validate second year = first year + 1
        $parts = explode('/', $request->label);
        $first = (int) $parts[0];
        $second = (int) $parts[1];
        if ($second !== $first + 1) {
            return response()->json([
                'success' => false,
                'message' => 'Le format doit être année/année+1 (ex: 2025/2026)',
            ], 422);
        }

        // Block creation if there's a non-archived current year
        $currentYear = AnneeAcademique::where('is_current', true)->first();
        if ($currentYear && !$currentYear->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez archiver l\'année courante (' . $currentYear->label . ') avant d\'en créer une nouvelle.',
            ], 422);
        }

        $annee = AnneeAcademique::create([
            'label' => $request->label,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'is_current' => false,
        ]);

        return response()->json([
            'success' => true,
            'data' => $annee,
        ], 201);
    }

    public function setCurrent(Request $request, $id): JsonResponse
    {
        $annee = AnneeAcademique::findOrFail($id);

        if ($annee->is_archived) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de définir une année archivée comme année courante.',
            ], 422);
        }

        DB::transaction(function () use ($annee) {
            AnneeAcademique::where('is_current', true)->update(['is_current' => false, 'is_archived' => true]);
            $annee->update(['is_current' => true]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Année courante mise à jour',
        ]);
    }

    public function archive($id): JsonResponse
    {
        $annee = AnneeAcademique::withCount(['etudiants', 'groupes'])->findOrFail($id);

        $counts = [
            'etudiants' => $annee->etudiants_count,
            'groupes'   => $annee->groupes_count,
        ];

        $annee->update([
            'is_current' => false,
            'is_archived' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Année archivée',
            'counts'  => $counts,
        ]);
    }

    public function current(): JsonResponse
    {
        $annee = AnneeAcademique::where('is_current', true)->first();

        $warning = null;
        if ($annee && $annee->end_date) {
            $daysLeft = now()->diffInDays($annee->end_date);
            if ($daysLeft <= 30) {
                if ($daysLeft > 0) {
                    $warning = [
                        'days_left' => $daysLeft,
                        'message' => "L'année académique se termine dans {$daysLeft} jour(s)",
                    ];
                } else {
                    $warning = [
                        'days_left' => 0,
                        'message' => "L'année académique est terminée. Veuillez l'archiver.",
                        'can_archive' => true,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $annee,
            'warning' => $warning,
        ]);
    }

    public function stats($id): JsonResponse
    {
        try {
            $annee = AnneeAcademique::with(['groupes.niveau.filiere', 'etudiants'])->findOrFail($id);

            // Query students by status
            $graduates = Etudiant::where('annee_academique_id', $id)
                ->where('status', 'graduate')
                ->with(['groupe.niveau.filiere', 'anneeAcademique'])
                ->orderBy('nom_prenom')
                ->get();

            $active = Etudiant::where('annee_academique_id', $id)
                ->where('status', 'active')
                ->count();

            $dropped = Etudiant::where('annee_academique_id', $id)
                ->where('status', 'dropped_out')
                ->count();

            // Aggregate by filière
            $graduatesByFiliere = $graduates->groupBy(fn($s) => $s->groupe?->niveau?->filiere?->nom ?? 'Unknown')
                ->map(fn($group) => [
                    'filiere' => $group->first()?->groupe?->niveau?->filiere?->nom ?? 'Unknown',
                    'count' => $group->count(),
                    'students' => $group->map(fn($s) => [
                        'id' => $s->id,
                        'nom_prenom' => $s->nom_prenom,
                        'numero_inscription' => $s->numero_inscription,
                        'niveau' => $s->groupe?->niveau?->numero,
                    ])->values(),
                ])
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'annee' => $annee,
                    'graduates' => $graduates,
                    'graduates_count' => $graduates->count(),
                    'graduates_by_filiere' => $graduatesByFiliere,
                    'active_count' => $active,
                    'dropped_count' => $dropped,
                    'total_students' => $active + $graduates->count() + $dropped,
                    'groups_count' => $annee->groupes->count(),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('AnneeAcademiqueController::stats error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du chargement des statistiques',
            ], 500);
        }
    }
}
