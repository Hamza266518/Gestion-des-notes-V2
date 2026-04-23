<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Groupe;
use Illuminate\Http\Request;

class GroupeController extends Controller
{
    public function index(Request $request)
    {
        $groupes = Groupe::with(['niveau.filiere', 'anneeAcademique'])
            ->when($request->niveau_id, fn($q) => $q->where('niveau_id', $request->niveau_id))
            ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
            ->get();
        return response()->json(['success' => true, 'data' => $groupes]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'niveau_id'           => 'required|exists:niveaux,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'nom'                 => 'required|string',
            'promotion'           => 'required|string',
        ]);
        $groupe = Groupe::create($request->all());
        return response()->json(['success' => true, 'data' => $groupe, 'message' => 'Groupe créé']);
    }

    public function update(Request $request, $id)
    {
        $groupe = Groupe::findOrFail($id);
        $groupe->update($request->all());
        return response()->json(['success' => true, 'data' => $groupe, 'message' => 'Groupe mis à jour']);
    }

    public function destroy($id)
    {
        Groupe::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Groupe supprimé']);
    }
}