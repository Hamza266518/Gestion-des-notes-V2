<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Niveau;
use Illuminate\Http\Request;

class NiveauController extends Controller
{
    public function index(Request $request)
    {
        $niveaux = Niveau::with('filiere')
            ->when($request->filiere_id, fn($q) => $q->where('filiere_id', $request->filiere_id))
            ->get();
        return response()->json(['success' => true, 'data' => $niveaux]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'filiere_id' => 'required|exists:filieres,id',
            'numero'     => 'required|integer',
        ]);
        $niveau = Niveau::create($request->all());
        return response()->json(['success' => true, 'data' => $niveau, 'message' => 'Niveau créé']);
    }

    public function destroy($id)
    {
        Niveau::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Niveau supprimé']);
    }
}