<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;

class NoteAdminController extends Controller
{
    public function index(Request $request)
    {
        try {
            $notes = Note::with(['etudiant', 'controle.sequence.unite'])
                ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
                ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
                ->get();
            return response()->json(['success' => true, 'data' => $notes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des notes'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate(['valeur' => 'required|numeric|min:0|max:20']);
            $note = Note::findOrFail($id);
            $note->update(['valeur' => $request->valeur]);
            return response()->json(['success' => true, 'data' => $note, 'message' => 'Note mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la note'], 500);
        }
    }
}
