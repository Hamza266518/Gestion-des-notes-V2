<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filiere;
use Illuminate\Http\Request;

class FiliereController extends Controller
{
    public function index()
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => Filiere::with(['niveaux.groupes'])->get(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des filières'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom'           => 'required|string',
                'code'          => 'required|string|unique:filieres,code',
                'section'       => 'required|string',
                'nombre_annees' => 'required|integer|min:1|max:5',
            ]);

            $filiere = Filiere::create($request->all());
            return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière créée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la filière'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $filiere = Filiere::findOrFail($id);
            $request->validate([
                'code' => 'string|unique:filieres,code,' . $id,
            ]);
            $filiere->update($request->all());
            return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la filière'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Filiere::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Filière supprimée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression de la filière'], 500);
        }
    }
}
