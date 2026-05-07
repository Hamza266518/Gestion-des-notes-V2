<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

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
            'label' => 'required|string|max:255|unique:annees_academiques',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

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
        AnneeAcademique::where('is_current', true)->update(['is_current' => false]);

        $annee = AnneeAcademique::findOrFail($id);
        $annee->update(['is_current' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Année courante mise à jour',
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $annee = AnneeAcademique::findOrFail($id);

        if ($annee->etudiants()->exists() || $annee->groupes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer. Cette année contient des données associées.',
            ], 422);
        }

        $annee->delete();

        return response()->json([
            'success' => true,
            'message' => 'Année supprimée',
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
}
