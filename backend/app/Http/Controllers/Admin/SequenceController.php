<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sequence;
use Illuminate\Http\Request;

class SequenceController extends Controller
{
    public function index(Request $request)
    {
        $sequences = Sequence::with(['unite', 'controles'])
            ->when($request->unite_id, fn($q) => $q->where('unite_id', $request->unite_id))
            ->orderBy('ordre')
            ->get();
        return response()->json(['success' => true, 'data' => $sequences]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'unite_id'         => 'required|exists:unites,id',
            'nom'              => 'required|string',
            'coefficient'      => 'required|integer',
            'nombre_controles' => 'required|integer|min:1|max:3',
        ]);
        $sequence = Sequence::create($request->all());
        return response()->json(['success' => true, 'data' => $sequence, 'message' => 'Séquence créée']);
    }

    public function update(Request $request, $id)
    {
        $sequence = Sequence::findOrFail($id);
        $sequence->update($request->all());
        return response()->json(['success' => true, 'data' => $sequence, 'message' => 'Séquence mise à jour']);
    }

    public function destroy($id)
    {
        Sequence::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Séquence supprimée']);
    }

    public function toggleActive($id)
    {
        $sequence = Sequence::findOrFail($id);
        $sequence->update(['is_active' => !$sequence->is_active]);
        return response()->json(['success' => true, 'data' => $sequence]);
    }
}