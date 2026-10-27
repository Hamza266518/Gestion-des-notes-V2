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
            $groupes = Groupe::with(['niveau.filiere', 'anneeAcademique'])->withCount('etudiants')
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
        // Outside the try on purpose: validate() throws ValidationException,
        // which extends Exception, so catching it here turned every bad payload
        // into a 500 instead of a 422 naming the offending field.
        $request->validate([
            'niveau_id'           => 'required|exists:niveaux,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'nom'                 => 'required|string|max:50',
            'promotion'           => 'required|string|max:20',
        ]);

        $exists = Groupe::where('niveau_id', $request->niveau_id)
            ->where('annee_academique_id', $request->annee_academique_id)
            ->whereRaw('LOWER(nom) = ?', [mb_strtolower(trim($request->nom))])
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Ce groupe existe déjà pour ce niveau et cette année'], 422);
        }

        try {
            $groupe = Groupe::create([
                'niveau_id' => $request->niveau_id,
                'annee_academique_id' => $request->annee_academique_id,
                'nom' => trim($request->nom),
                'promotion' => trim($request->promotion),
            ]);
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
