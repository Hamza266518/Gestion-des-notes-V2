<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use Illuminate\Http\Request;

class EtudiantController extends Controller
{
    public function index(Request $request)
    {
        $etudiants = Etudiant::with(['groupe.niveau.filiere', 'anneeAcademique'])
            ->when($request->groupe_id, fn($q) => $q->where('groupe_id', $request->groupe_id))
            ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
            ->when($request->search, fn($q) => $q->where('nom_prenom', 'like', '%' . $request->search . '%')
                ->orWhere('cin', 'like', '%' . $request->search . '%'))
            ->get();
        return response()->json(['success' => true, 'data' => $etudiants]);
    }

    public function update(Request $request, $id)
    {
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
    }

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->user()->delete();
        $etudiant->delete();
        return response()->json(['success' => true, 'message' => 'Stagiaire supprimé']);
    }
}