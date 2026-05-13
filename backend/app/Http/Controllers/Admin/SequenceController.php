<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Models\Sequence;
use App\Traits\HasControleType;
use Illuminate\Http\Request;

class SequenceController extends Controller
{
    use HasControleType;
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
                'nombre_controles' => 'required|integer|min:1|max:10',
                'ordre'            => 'nullable|integer',
                'formateur_id'     => 'nullable|exists:formateurs,id',
            ]);

            $seqData = $request->only(['unite_id', 'nom', 'coefficient', 'nombre_controles', 'ordre']);
            $seqData['is_active'] = true;
            $sequence = Sequence::create($seqData);

            $nb = (int) $request->nombre_controles;
            for ($i = 1; $i <= $nb; $i++) {
                Controle::create([
                    'sequence_id' => $sequence->id,
                    'numero'      => $i,
                    'nom'         => 'Contrôle ' . $i,
                    'type'        => $this->getControleType($i, $nb),
                    'note_max'    => 20,
                ]);
            }

            return response()->json([
                'success' => true,
                'data'    => $sequence->load('controles'),
                'message' => 'Séquence créée',
            ]);
        } catch (\Exception $e) {
            \Log::error('SequenceController::store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la séquence'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $sequence = Sequence::findOrFail($id);
            $oldCount = $sequence->nombre_controles;

            $sequence->update($request->only(['nom', 'coefficient', 'nombre_controles', 'is_active']));

            if ($request->filled('nombre_controles')) {
                $newCount = (int) $request->nombre_controles;
                if ($newCount > $oldCount) {
                    for ($i = $oldCount + 1; $i <= $newCount; $i++) {
                        Controle::create([
                            'sequence_id' => $sequence->id,
                            'numero'      => $i,
                            'nom'         => 'Contrôle ' . $i,
                            'type'        => $this->getControleType($i, $newCount),
                            'note_max'    => 20,
                        ]);
                    }

                    $existing = Controle::where('sequence_id', $sequence->id)
                        ->where('numero', '<=', $oldCount)
                        ->get();
                    foreach ($existing as $ctrl) {
                        $ctrl->update(['type' => $this->getControleType($ctrl->numero, $newCount)]);
                    }
                } elseif ($newCount < $oldCount) {
                    $toDelete = Controle::where('sequence_id', $sequence->id)
                        ->where('numero', '>', $newCount)
                        ->withCount('notes')
                        ->get();

                    $totalNotes = $toDelete->sum('notes_count');
                    if ($totalNotes > 0) {
                        return response()->json([
                            'success' => false,
                            'message' => "Impossible de réduire le nombre de contrôles : {$totalNotes} note(s) existent pour les contrôles à supprimer."
                        ], 409);
                    }

                    Controle::where('sequence_id', $sequence->id)
                        ->where('numero', '>', $newCount)
                        ->delete();

                    $remaining = Controle::where('sequence_id', $sequence->id)->get();
                    foreach ($remaining as $ctrl) {
                        $ctrl->update(['type' => $this->getControleType($ctrl->numero, $newCount)]);
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data'    => $sequence->load('controles'),
                'message' => 'Séquence mise à jour',
            ]);
        } catch (\Exception $e) {
            \Log::error('SequenceController::update error: ' . $e->getMessage());
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

    public function renameControle(Request $request, $id)
    {
        try {
            $request->validate(['nom' => 'required|string|max:255']);
            $controle = Controle::findOrFail($id);
            $controle->update(['nom' => $request->nom]);
            return response()->json(['success' => true, 'data' => $controle]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du renommage'], 500);
        }
    }

    public function setControleType(Request $request, $id)
    {
        try {
            $request->validate(['type' => 'required|in:cc,theorique,pratique']);
            $controle = Controle::findOrFail($id);
            $controle->update(['type' => $request->type]);
            return response()->json(['success' => true, 'data' => $controle]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la modification du type'], 500);
        }
    }

}
