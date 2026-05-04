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
        ]);

        $annee = AnneeAcademique::create([
            'label' => $request->label,
            'is_current' => false,
        ]);

        return response()->json([
            'success' => true,
            'data' => $annee,
        ], 201);
    }

    public function setCurrent(Request $request, $id): JsonResponse
    {
        // Reset all to non-current
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

        // Check if has related data
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
        $annee = AnneeAcademique::findOrFail($id);
        $annee->update(['is_current' => false, 'is_archived' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Année archivée',
        ]);
    }

    public function current(): JsonResponse
    {
        $annee = AnneeAcademique::where('is_current', true)->first();

        return response()->json([
            'success' => true,
            'data' => $annee,
        ]);
    }
}
