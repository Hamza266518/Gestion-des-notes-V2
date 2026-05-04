<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use Illuminate\Http\Request;

class EtudiantController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Etudiant::with(['groupe.niveau.filiere', 'anneeAcademique']);

            if ($request->filled('annee_academique_id')) {
                $query->where('annee_academique_id', $request->annee_academique_id);
            }

            if ($request->filled('groupe_id')) {
                $query->where('groupe_id', $request->groupe_id);
            }

            if ($request->filled('niveau_id')) {
                $query->whereHas('groupe', fn($q) => $q->where('niveau_id', $request->niveau_id));
            }

            if ($request->filled('filiere_id')) {
                $query->whereHas('groupe.niveau', fn($q) => $q->where('filiere_id', $request->filiere_id));
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('nom_prenom', 'like', '%' . $search . '%')
                      ->orWhere('cin', 'like', '%' . $search . '%');
                });
            }

            $etudiants = $query->get();
            return response()->json(['success' => true, 'data' => $etudiants]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des étudiants'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'nom_prenom'     => 'required|string',
                'cin'            => 'required|string',
                'date_naissance' => 'nullable|date',
                'groupe_id'      => 'required|exists:groupes,id',
            ]);

            $etudiant = Etudiant::with('user')->findOrFail($id);

            $newEmail = strtolower($request->cin) . '@ifp.ma';

            $etudiant->update([
                'nom_prenom'     => $request->nom_prenom,
                'cin'            => strtoupper($request->cin),
                'date_naissance' => $request->date_naissance,
                'groupe_id'      => $request->groupe_id,
            ]);

            if ($etudiant->user) {
                $etudiant->user->update([
                    'name'  => $request->nom_prenom,
                    'email' => $newEmail,
                ]);
            }

            return response()->json(['success' => true, 'data' => $etudiant->load('groupe.niveau.filiere'), 'message' => 'Étudiant modifié']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la modification de l\'étudiant'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $etudiant = Etudiant::findOrFail($id);
            $etudiant->user()->delete();
            $etudiant->delete();
            return response()->json(['success' => true, 'message' => 'Stagiaire supprimé']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression de l\'étudiant'], 500);
        }
    }
}
