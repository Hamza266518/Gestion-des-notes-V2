<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filiere;
use Illuminate\Http\Request;

class FiliereController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => Filiere::with(['niveaux.groupes'])->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nom'           => 'required|string',
            'code'          => 'required|string|unique:filieres,code',
            'section'       => 'required|string',
            'nombre_annees' => 'required|integer|min:1|max:5',
        ]);

        $filiere = Filiere::create($request->all());
        return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière créée']);
    }

    public function update(Request $request, $id)
    {
        $filiere = Filiere::findOrFail($id);
        $request->validate([
            'code' => 'string|unique:filieres,code,' . $id,
        ]);
        $filiere->update($request->all());
        return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière mise à jour']);
    }

    public function destroy($id)
    {
        Filiere::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Filière supprimée']);
    }
}