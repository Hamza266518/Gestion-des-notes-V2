<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Examen;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examens = Examen::with(['etudiant', 'unite'])
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->when($request->unite_id, fn($q) => $q->where('unite_id', $request->unite_id))
            ->when($request->bloc, fn($q) => $q->where('bloc', $request->bloc))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->get();

        return response()->json(['success' => true, 'data' => $examens]);
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'examens' => 'required|array',
            'examens.*.etudiant_id' => 'required|exists:etudiants,id',
            'examens.*.valeur' => 'nullable|numeric|min:0|max:20',
            'unite_id' => 'required|exists:unites,id',
            'bloc' => 'required|integer|in:1,2',
            'type' => 'required|in:theorique,pratique',
            'semestre' => 'required|integer|in:1,2',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        $admin = Auth::user();
        $saved = 0;

        foreach ($request->examens as $data) {
            Examen::updateOrCreate(
                [
                    'etudiant_id' => $data['etudiant_id'],
                    'unite_id' => $request->unite_id,
                    'bloc' => $request->bloc,
                    'type' => $request->type,
                    'semestre' => $request->semestre,
                    'annee_academique_id' => $request->annee_academique_id,
                ],
                ['valeur' => $data['valeur']]
            );
            $saved++;
        }

        ActivityLog::create([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'action_type' => 'update',
            'description' => "{$admin->name} a enregistre {$saved} note(s) d'examen {$request->type} pour le bloc {$request->bloc}",
            'model_type' => 'App\Models\Examen',
        ]);

        return response()->json(['success' => true, 'message' => "{$saved} note(s) enregistree(s)"]);
    }
}
