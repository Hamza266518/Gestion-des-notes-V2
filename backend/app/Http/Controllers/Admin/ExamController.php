<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\ActivityLog;
use App\Services\PublicationLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExamController extends Controller
{
    protected $locks;

    public function __construct(PublicationLockService $locks)
    {
        $this->locks = $locks;
    }

    public function index(Request $request)
    {
        $examens = Examen::with(['etudiant', 'unite'])
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->when($request->unite_id, fn($q) => $q->where('unite_id', $request->unite_id))
            ->when($request->bloc, fn($q) => $q->where('bloc', $request->bloc))
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
            ->get();

        return response()->json(['success' => true, 'data' => $examens]);
    }

    public function bulkStore(Request $request)
    {
        $request->validate([
            'examens' => 'required|array',
            'examens.*.etudiant_id' => 'required|exists:etudiants,id',
            'examens.*.valeur' => 'nullable|numeric|min:0|max:20',
            'examens.*.est_absent' => 'nullable|boolean',
            'unite_id' => 'required|exists:unites,id',
            'bloc' => 'required|integer|in:1,2',
            'type' => 'required|in:theorique,pratique',
            'semestre' => 'required|integer|in:1,2',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        // Exam grades carry no control, so the lock is resolved from the group
        // each student actually belongs to. Resolved before any write: a batch
        // that touches one published group is refused whole, so an admin is
        // never told a grade saved when it did not.
        $etudiantIds = collect($request->examens)->pluck('etudiant_id')->map(fn ($id) => (int) $id);
        $groupeIds = Etudiant::whereIn('id', $etudiantIds)->pluck('groupe_id')
            ->map(fn ($id) => (int) $id)->all();

        if ($this->locks->unGroupeVerrouille(
            $groupeIds,
            (int) $request->annee_academique_id,
            (int) $request->semestre
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier des notes d\'examen : les résultats de ce groupe sont publiés.',
            ], 403);
        }

        $admin = Auth::user();
        $saved = 0;

        foreach ($request->examens as $data) {
            $estAbsent = (bool) ($data['est_absent'] ?? false);

            Examen::updateOrCreate(
                [
                    'etudiant_id' => $data['etudiant_id'],
                    'unite_id' => $request->unite_id,
                    'bloc' => $request->bloc,
                    'type' => $request->type,
                    'semestre' => $request->semestre,
                    'annee_academique_id' => $request->annee_academique_id,
                ],
                [
                    'valeur' => $estAbsent ? 0 : $data['valeur'],
                    'est_absent' => $estAbsent,
                ]
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
