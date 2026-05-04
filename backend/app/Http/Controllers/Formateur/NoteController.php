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