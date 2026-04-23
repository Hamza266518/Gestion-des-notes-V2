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

    public function destroy($id)
    {
        $etudiant = Etudiant::findOrFail($id);
        $etudiant->user()->delete();
        $etudiant->delete();
        return response()->json(['success' => true, 'message' => 'Stagiaire supprimé']);
    }
}