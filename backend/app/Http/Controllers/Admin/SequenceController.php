<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sequence;
use Illuminate\Http\Request;

class SequenceController extends Controller
{
    public function index(Request $request)
    {
        try {
            $sequences = Sequence::with(['unite', 'controles'])
                ->when($request->unite_id, fn($q) => $q->where('unite_id', $request->unite_id))
                ->orderBy('ordre')
                ->get();
            return response()->json(['success' => true, 'data' => $sequences]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des séquences'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'unite_id'         => 'required|exists:unites,id',
                'nom'              => 'required|string',
                'coefficient'      => 'required|integer',
                'nombre_controles' => 'required|integer|min:1|max:3',
            ]);
            $sequence = Sequence::create($request->all());
            return response()->json(['success' => true, 'data' => $sequence, 'message' => 'Séquence créée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la séquence'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $sequence = Sequence::findOrFail($id);
            $sequence->update($request->all());
            return response()->json(['success' => true, 'data' => $sequence, 'message' => 'Séquence mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la séquence'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Sequence::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Séquence supprimée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression de la séquence'], 500);
        }
    }

    public function toggleActive($id)
    {
        try {
            $sequence = Sequence::findOrFail($id);
            $sequence->update(['is_active' => !$sequence->is_active]);
            return response()->json(['success' => true, 'data' => $sequence]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du changement de statut'], 500);
        }
    }
}
