<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Services\MoyenneService;
use Illuminate\Http\Request;

class DiplomeController extends Controller
{
    protected $moyenneService;

    public function __construct(MoyenneService $moyenneService)
    {
        $this->moyenneService = $moyenneService;
    }

    public function index(Request $request)
    {
        $diplomes = Diplome::with(['etudiant.groupe.niveau.filiere', 'anneeAcademique'])
            ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
            ->get();
        return response()->json(['success' => true, 'data' => $diplomes]);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'etudiant_id'         => 'required|exists:etudiants,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'semestre'            => 'required|integer|in:1,2',
        ]);

        $moyenne = $this->moyenneService->moyenneGenerale(
            $request->etudiant_id,
            $request->semestre,
            $request->annee_academique_id
        );

        if (!$moyenne) {
            return response()->json(['success' => false, 'message' => 'Notes incomplètes'], 400);
        }

        $diplome = Diplome::updateOrCreate(
            ['etudiant_id' => $request->etudiant_id, 'annee_academique_id' => $request->annee_academique_id],
            ['moyenne_generale' => $moyenne, 'mention' => $this->moyenneService->mention($moyenne)]
        );

        return response()->json(['success' => true, 'data' => $diplome, 'message' => 'Diplôme généré']);
    }

    public function markPrinted($id)
    {
        $diplome = Diplome::findOrFail($id);
        $diplome->update(['is_printed' => true, 'printed_at' => now()]);
        return response()->json(['success' => true, 'data' => $diplome]);
    }
}