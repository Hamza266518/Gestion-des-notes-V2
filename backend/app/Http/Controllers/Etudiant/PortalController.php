<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Services\MoyenneService;

class PortalController extends Controller
{
    protected $moyenneService;

    public function __construct(MoyenneService $moyenneService)
    {
        $this->moyenneService = $moyenneService;
    }

    public function monBulletin()
    {
        $user     = auth()->user();
        $etudiant = $user->etudiant->load('groupe.niveau.filiere');

        if (!$etudiant) {
            return response()->json(['success' => false, 'message' => 'Stagiaire non trouvé'], 404);
        }

        $anneeId   = $etudiant->annee_academique_id;
        $groupeId  = $etudiant->groupe_id;
        $filiereId = $etudiant->groupe->niveau->filiere_id;
        $bulletin  = [];

        foreach ([1, 2] as $semestre) {
            $publication = SemestrePublication::where('groupe_id', $groupeId)
                ->where('annee_academique_id', $anneeId)
                ->where('semestre', $semestre)
                ->where('is_published', true)
                ->first();

            if (!$publication) {
                $bulletin[$semestre] = ['published' => false, 'unites' => []];
                continue;
            }

            $unites = Unite::where('filiere_id', $filiereId)
                ->where('semestre', $semestre)
                ->where('is_active', true)
                ->with('sequences.controles')
                ->orderBy('ordre')
                ->get();

            $unitesData = [];

            foreach ($unites as $unite) {
                $sequencesData = [];

                foreach ($unite->sequences as $sequence) {
                    $controlesData = [];

                    foreach ($sequence->controles as $controle) {
                        $note = Note::where('etudiant_id', $etudiant->id)
                            ->where('controle_id', $controle->id)
                            ->first();
                        $controlesData[] = [
                            'numero' => $controle->numero,
                            'valeur' => $note?->valeur,
                        ];
                    }

                    $sequencesData[] = [
                        'id'          => $sequence->id,
                        'nom'         => $sequence->nom,
                        'coefficient' => $sequence->coefficient,
                        'controles'   => $controlesData,
                        'moyenne'     => $this->moyenneService->moyenneSequence($etudiant->id, $sequence->id),
                    ];
                }

                $unitesData[] = [
                    'id'          => $unite->id,
                    'nom'         => $unite->nom,
                    'coefficient' => $unite->coefficient,
                    'sequences'   => $sequencesData,
                    'moyenne'     => $this->moyenneService->moyenneUnite($etudiant->id, $unite->id),
                ];
            }

            $bulletin[$semestre] = [
                'published'         => true,
                'published_at'      => $publication->published_at,
                'unites'            => $unitesData,
                'moyenne_generale'  => $this->moyenneService->moyenneGenerale($etudiant->id, $semestre, $anneeId),
            ];
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'etudiant' => $etudiant,
                'bulletin' => $bulletin,
            ],
        ]);
    }
}