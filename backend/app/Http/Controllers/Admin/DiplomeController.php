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
        try {
            $diplomes = Diplome::with([
                'etudiant.groupe.niveau.filiere',
                'anneeAcademique'
            ])
            ->when($request->annee_academique_id, fn($q) =>
                $q->where('annee_academique_id', $request->annee_academique_id)
            )
            ->get();

            return response()->json(['success' => true, 'data' => $diplomes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function generate(Request $request)
    {
        try {
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
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération du diplôme'], 500);
        }
    }

    public function markPrinted($id)
    {
        try {
            $diplome = Diplome::findOrFail($id);
            $diplome->update(['is_printed' => true, 'printed_at' => now()]);
            return response()->json(['success' => true, 'data' => $diplome]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour'], 500);
        }
    }

    public function download($id)
    {
        try {
            $diplome = Diplome::with([
                'etudiant.groupe.niveau.filiere',
                'anneeAcademique'
            ])->findOrFail($id);

            $etudiant = $diplome->etudiant;
            $filiere  = $etudiant->groupe?->niveau?->filiere;
            $groupe   = $etudiant->groupe;

            return response()->json([
                'success' => true,
                'data' => [
                    'nom_prenom'         => $etudiant->nom_prenom,
                    'type_formation'     => $filiere?->type_formation ?? '',
                    'date_naissance'     => $etudiant->date_naissance
                        ? \Carbon\Carbon::parse($etudiant->date_naissance)->format('d/m/Y') : '',
                    'lieu_naissance'     => $etudiant->lieu_naissance ?? '',
                    'cin'                => $etudiant->cin ?? '',
                    'nationalite'        => $etudiant->nationalite ?? 'Marocaine',
                    'numero_inscription' => $etudiant->numero_inscription ?? '',
                    'date_inscription'   => $etudiant->date_inscription
                        ? \Carbon\Carbon::parse($etudiant->date_inscription)->format('d/m/Y') : '',
                    'filiere'            => $filiere?->nom ?? '',
                    'promotion'          => $groupe?->promotion ?? '',
                    'moyenne'            => number_format($diplome->moyenne_generale, 2),
                    'mention'            => $diplome->mention,
                    'date_delivrance'    => now()->format('d/m/Y'),
                    // Arabic fields
                    'nom_ar'              => $etudiant->nom_ar ?? '',
                    'date_naissance_ar'     => $etudiant->date_naissance_ar
                        ?? ($etudiant->date_naissance
                            ? \Carbon\Carbon::parse($etudiant->date_naissance)->format('d/m/Y') : ''),
                    'lieu_naissance_ar'     => $etudiant->lieu_naissance_ar
                        ?? ($etudiant->lieu_naissance ?? ''),
                    'cin_ar'                => $etudiant->cin_ar
                        ?? ($etudiant->cin ?? ''),
                    'nationalite_ar'        => $etudiant->nationalite_ar
                        ?? ($etudiant->nationalite ?? 'Marocaine'),
                    'numero_inscription_ar' => $etudiant->numero_inscription_ar
                        ?? ($etudiant->numero_inscription ?? ''),
                    'date_inscription_ar'   => $etudiant->date_inscription_ar
                        ?? ($etudiant->date_inscription
                            ? \Carbon\Carbon::parse($etudiant->date_inscription)->format('d/m/Y') : ''),
                    'filiere_ar'            => $filiere?->nom_ar ?? '',
                    'type_formation_ar'     => $filiere?->type_formation ?? '',
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function generateAll(Request $request)
    {
        try {
            $request->validate([
                'annee_academique_id' => 'required|exists:annees_academiques,id',
            ]);

            $anneeId = $request->annee_academique_id;

            $etudiants = Etudiant::where('annee_academique_id', $anneeId)->get();

            $created = 0;
            $skipped = 0;

            foreach ($etudiants as $etudiant) {
                try {
                    $moyenne = $this->moyenneService->moyenneGenerale(
                        $etudiant->id,
                        null,
                        $anneeId
                    );

                    if (!$moyenne || $moyenne < 10) {
                        $skipped++;
                        continue;
                    }

                    Diplome::updateOrCreate(
                        ['etudiant_id' => $etudiant->id, 'annee_academique_id' => $anneeId],
                        ['moyenne_generale' => $moyenne, 'mention' => $this->moyenneService->getMention($moyenne)]
                    );

                    $created++;
                } catch (\Exception $e) {
                    $skipped++;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'created' => $created,
                    'skipped' => $skipped,
                    'total'   => $etudiants->count(),
                ],
                'message' => "{$created} diplôme(s) généré(s) pour les étudiants admis, {$skipped} non-admis"
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
