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
        $etudiant = $user->etudiant;

        if (!$etudiant) {
            return response()->json(['success' => false, 'message' => 'Stagiaire non trouvé'], 404);
        }

        $etudiant->load('groupe.niveau.filiere');

        $anneeId   = $etudiant->annee_academique_id;
        $groupeId  = $etudiant->groupe_id;
        $filiereId = $etudiant->groupe->niveau->filiere_id;

        // Check publication states
        $publications = SemestrePublication::where('groupe_id', $groupeId)
            ->where('annee_academique_id', $anneeId)
            ->get();

        $pubStates = [
            'notes_s1' => false,
            'notes_s2' => false,
            'bulletin' => false,
        ];

        foreach ($publications as $pub) {
            if ($pub->is_published) {
                $pubStates[$pub->type] = true;
            }
        }

        $result = [
            'etudiant' => $etudiant,
            'publications' => $pubStates,
            'notes_s1' => [],
            'notes_s2' => [],
            'bulletin' => null,
        ];

        // Notes S1 - raw scores only
        if ($pubStates['notes_s1']) {
            $result['notes_s1'] = $this->getRawNotes($etudiant->id, $filiereId, 1, $anneeId);
        }

        // Notes S2 - raw scores only
        if ($pubStates['notes_s2']) {
            $result['notes_s2'] = $this->getRawNotes($etudiant->id, $filiereId, 2, $anneeId);
        }

        // Bulletin - full calculations
        if ($pubStates['bulletin']) {
            $result['bulletin'] = $this->getBulletin($etudiant, $filiereId, $groupeId, $anneeId);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    protected function getRawNotes($etudiantId, $filiereId, $semestre, $anneeId)
    {
        $unites = Unite::where('filiere_id', $filiereId)
            ->where('semestre', $semestre)
            ->where('is_active', true)
            ->with('sequences.controles')
            ->orderBy('ordre')
            ->get();

        $notes = [];

        foreach ($unites as $unite) {
            foreach ($unite->sequences as $sequence) {
                foreach ($sequence->controles as $controle) {
                    $note = Note::where('etudiant_id', $etudiantId)
                        ->where('controle_id', $controle->id)
                        ->first();
                    $notes[] = [
                        'unite_nom' => $unite->nom,
                        'sequence_nom' => $sequence->nom,
                        'controle_numero' => $controle->numero,
                        'valeur' => $note?->valeur,
                    ];
                }
            }
        }

        return $notes;
    }

    protected function getBulletin($etudiant, $filiereId, $groupeId, $anneeId)
    {
        $bulletin = [
            'semestres' => [1 => [], 2 => []],
            'examens' => [],
            'stage_note' => null,
            'moyenne_generale' => null,
            'mention' => null,
        ];

        foreach ([1, 2] as $semestre) {
            $unites = Unite::where('filiere_id', $filiereId)
                ->where('semestre', $semestre)
                ->where('is_active', true)
                ->with('sequences.controles')
                ->orderBy('ordre')
                ->get();

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
                        'nom'         => $sequence->nom,
                        'coefficient' => $sequence->coefficient,
                        'controles'   => $controlesData,
                        'moyenne'     => $this->moyenneService->moyenneSequence($etudiant->id, $sequence->id),
                    ];
                }
                $bulletin['semestres'][$semestre][] = [
                    'nom'         => $unite->nom,
                    'coefficient' => $unite->coefficient,
                    'sequences'   => $sequencesData,
                    'moyenne'     => $this->moyenneService->moyenneUnite($etudiant->id, $unite->id),
                ];
            }
        }

        $bulletin['moyenne_generale'] = $this->moyenneService->moyenneGenerale($etudiant->id, null, $anneeId);
        $bulletin['mention'] = $this->moyenneService->getMention($bulletin['moyenne_generale']);

        return $bulletin;
    }
}