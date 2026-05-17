<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        $formateur = auth()->user()->formateur;
        $notes = Note::with(['etudiant', 'controle.sequence.unite'])
            ->whereHas('controle.sequence.formateurs', fn($q) =>
                $q->where('formateur_id', $formateur->id)
            )
            ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
            ->when($request->sequence_id, fn($q) => $q->whereHas('controle', fn($q) => $q->where('sequence_id', $request->sequence_id)))
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->get();
        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function mySequences(Request $request)
    {
        $formateur = auth()->user()->formateur;
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();
        return response()->json(['success' => true, 'data' => $sequences]);
    }

    public function update(Request $request, $id)
    {
        $formateur = auth()->user()->formateur;
        $note = Note::with('controle.sequence')->findOrFail($id);

        $hasAccess = $formateur->sequences()->where('sequence_id', $note->controle->sequence_id)->exists();
        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        if ($note->is_confirmed) {
            return response()->json(['success' => false, 'message' => 'Note déjà confirmée, modification impossible'], 403);
        }

        $request->validate(['valeur' => 'required|numeric|min:0|max:20']);
        $note->update(['valeur' => $request->valeur]);

        return response()->json(['success' => true, 'data' => $note, 'message' => 'Note mise à jour']);
    }

    public function scanData(Request $request)
    {
        $formateur = auth()->user()->formateur;
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();

        $filieres = $sequences->pluck('unite.filiere')->filter()->unique('id')->values();

        return response()->json([
            'success' => true,
            'data' => [
                'filieres' => $filieres,
                'sequences' => $sequences,
            ],
        ]);
    }
}