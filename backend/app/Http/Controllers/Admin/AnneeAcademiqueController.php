<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use Illuminate\Http\Request;

class AnneeAcademiqueController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => AnneeAcademique::orderBy('created_at', 'desc')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['label' => 'required|string|unique:annees_academiques,label']);

        $annee = AnneeAcademique::create(['label' => $request->label, 'is_current' => false]);

        return response()->json(['success' => true, 'data' => $annee, 'message' => 'Année créée']);
    }

    public function setCurrent($id)
    {
        AnneeAcademique::query()->update(['is_current' => false]);
        $annee = AnneeAcademique::findOrFail($id);
        $annee->update(['is_current' => true]);

        return response()->json(['success' => true, 'data' => $annee, 'message' => 'Année courante mise à jour']);
    }

    public function destroy($id)
    {
        $annee = AnneeAcademique::findOrFail($id);
        if ($annee->is_current) {
            return response()->json(['success' => false, 'message' => 'Impossible de supprimer l\'année courante'], 400);
        }
        $annee->delete();
        return response()->json(['success' => true, 'message' => 'Année supprimée']);
    }
}