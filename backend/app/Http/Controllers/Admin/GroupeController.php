<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use Illuminate\Http\Request;

class GroupeController extends Controller
{
    public function index(Request $request)
    {
        try {
            $groupes = Groupe::with(['niveau.filiere', 'anneeAcademique'])
                ->when($request->niveau_id, fn($q) => $q->where('niveau_id', $request->niveau_id))
                ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
                ->get();
            return response()->json(['success' => true, 'data' => $groupes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des groupes'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'niveau_id'           => 'required|exists:niveaux,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'nom'                 => 'required|string',
                'promotion'           => 'required|string',
            ]);
            $groupe = Groupe::create($request->all());
            return response()->json(['success' => true, 'data' => $groupe, 'message' => 'Groupe créé']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création du groupe'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $groupe = Groupe::findOrFail($id);
            $groupe->update($request->all());
            return response()->json(['success' => true, 'data' => $groupe, 'message' => 'Groupe mis à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour du groupe'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $groupe = Groupe::withCount('etudiants')->findOrFail($id);
            if ($groupe->etudiants_count > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Impossible de supprimer. Ce groupe contient {$groupe->etudiants_count} étudiant(s).",
                ], 422);
            }
            $groupe->delete();
            return response()->json(['success' => true, 'message' => 'Groupe supprimé']);
        } catch (\Exception $e) {
            \Log::error('GroupeController::destroy error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression du groupe'], 500);
        }
    }
}
