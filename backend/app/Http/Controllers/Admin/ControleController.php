<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Models\Sequence;
use Illuminate\Http\Request;

class ControleController extends Controller
{
    public function index(Request $request)
    {
        try {
            $controles = Controle::with(['sequence', 'formateur'])
                ->when($request->sequence_id, fn($q) => $q->where('sequence_id', $request->sequence_id))
                ->get();
            return response()->json(['success' => true, 'data' => $controles]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des contrôles'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'sequence_id' => 'required|exists:sequences,id',
                'numero'      => 'required|integer',
                'note_max'    => 'integer',
            ]);
            $controle = Controle::create($request->all());
            return response()->json(['success' => true, 'data' => $controle, 'message' => 'Contrôle créé']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création du contrôle'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Controle::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Contrôle supprimé']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression du contrôle'], 500);
        }
    }

    public function generateForSequence($sequenceId)
    {
        try {
            $sequence = Sequence::findOrFail($sequenceId);
            $created  = [];

            for ($i = 1; $i <= $sequence->nombre_controles; $i++) {
                $exists = Controle::where('sequence_id', $sequenceId)->where('numero', $i)->exists();
                if (!$exists) {
                    $created[] = Controle::create([
                        'sequence_id' => $sequenceId,
                        'numero'      => $i,
                        'note_max'    => 20,
                    ]);
                }
            }

            return response()->json(['success' => true, 'data' => $created, 'message' => count($created) . ' contrôles créés']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération des contrôles'], 500);
        }
    }
}
