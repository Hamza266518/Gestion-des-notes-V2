<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\Note;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    public function index(Request $request)
    {
        $notes = Note::with(['etudiant', 'controle.sequence.unite'])
            ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->get();
        return response()->json(['success' => true, 'data' => $notes]);
    }
}