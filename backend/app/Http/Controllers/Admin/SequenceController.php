<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Models\Sequence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                'nombre_controles' => 'required|integer|min:1|max:10',
                'ordre'            => 'nullable|integer',
                'formateur_id'     => 'nullable|exists:formateurs,id',
            ]);

            $seqData = $request->only(['unite_id', 'nom', 'nombre_controles', 'ordre']);
            // The sequence "coefficient" is only a mirror of the control count
            // and is never used as a weight; keep the column in step without
            // asking the client for it.
            $seqData['coefficient'] = (int) $request->nombre_controles;
            $seqData['is_active'] = true;
            $sequence = Sequence::create($seqData);

            $nb = (int) $request->nombre_controles;
            for ($i = 1; $i <= $nb; $i++) {
                Controle::create([
                    'sequence_id' => $sequence->id,
                    'numero'      => $i,
                    'nom'         => 'Contrôle ' . $i,
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
        // Outside the try on purpose: validate() throws ValidationException,
        // which extends Exception, so catching it here would turn a bad payload
        // into a 500 instead of a 422 naming the offending field.
        $request->validate([
            'nombre_controles' => 'nullable|integer|min:1|max:10',
        ]);

        try {
            $sequence = Sequence::findOrFail($id);

            $data     = $request->only(['nom', 'nombre_controles', 'is_active']);
            $newCount = $request->filled('nombre_controles') ? (int) $request->nombre_controles : null;

            if ($newCount !== null) {
                // Mirror only; never a weight.
                $data['coefficient'] = $newCount;
            }

            // The reduction is checked before anything is written: the old code
            // saved the new count first and only then answered 409, so the
            // sequence showed a count nothing matched (it "just changed", no
            // contrôle was removed).
            if ($newCount !== null) {
                $totalNotes = Controle::where('sequence_id', $sequence->id)
                    ->where('numero', '>', $newCount)
                    ->withCount('notes')
                    ->get()
                    ->sum('notes_count');

                // Deleting the extra contrôles takes their notes with it (FK
                // cascade), so it is blocked by default and only runs when the
                // caller explicitly confirms with force=true.
                if ($totalNotes > 0 && ! $request->boolean('force')) {
                    return response()->json([
                        'success'               => false,
                        'requires_confirmation' => true,
                        'notes_count'           => $totalNotes,
                        'message'               => "Impossible de réduire le nombre de contrôles : {$totalNotes} note(s) existent pour les contrôles à supprimer.",
                    ], 409);
                }
            }

            // All-or-nothing so the count and the real contrôles never drift.
            DB::transaction(function () use ($sequence, $data, $newCount) {
                $sequence->update($data);

                if ($newCount === null) {
                    return;
                }

                // Reconcile 1..N instead of assuming 1..oldCount exist: an
                // earlier partial edit could leave holes, and creating from
                // oldCount + 1 then duplicated a numero.
                $existing = Controle::where('sequence_id', $sequence->id)
                    ->pluck('numero')
                    ->map(fn ($n) => (int) $n)
                    ->all();

                for ($i = 1; $i <= $newCount; $i++) {
                    if (! in_array($i, $existing, true)) {
                        Controle::create([
                            'sequence_id' => $sequence->id,
                            'numero'      => $i,
                            'nom'         => 'Contrôle ' . $i,
                            'note_max'    => 20,
                        ]);
                    }
                }

                Controle::where('sequence_id', $sequence->id)
                    ->where('numero', '>', $newCount)
                    ->delete();
            });

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

}
