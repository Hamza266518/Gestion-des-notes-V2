<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\MoyenneService;

class PortalController extends Controller
{
    protected $moyenneService;
    protected $bulletinService;

    public function __construct(MoyenneService $moyenneService, BulletinService $bulletinService)
    {
        $this->moyenneService = $moyenneService;
        $this->bulletinService = $bulletinService;
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
        $niveauNumero = $etudiant->groupe->niveau->numero;

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
            $result['notes_s1'] = $this->getRawNotes($etudiant->id, $filiereId, $niveauNumero, 1, $anneeId);
        }

        // Notes S2 - raw scores only
        if ($pubStates['notes_s2']) {
            $result['notes_s2'] = $this->getRawNotes($etudiant->id, $filiereId, $niveauNumero, 2, $anneeId);
        }

        // Bulletin - full calculations
        if ($pubStates['bulletin']) {
            $result['bulletin'] = $this->getBulletin($etudiant, $filiereId, $groupeId, $anneeId);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    protected function getRawNotes($etudiantId, $filiereId, $niveauNumero, $semestre, $anneeId)
    {
        $unites = Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $niveauNumero)
            ->where(function ($q) use ($semestre) {
                $q->where('semestre', $semestre)->orWhere('semestre', 0);
            })
            ->where('is_active', true)
            ->with('sequences.controles', 'controles')
            ->orderBy('ordre')
            ->get();

        // One query for all the student's notes instead of one per control.
        $controleIds = collect();
        foreach ($unites as $unite) {
            foreach ($unite->sequences as $s) {
                $controleIds = $controleIds->merge($s->controles->pluck('id'));
            }
            $controleIds = $controleIds->merge($unite->controles->pluck('id'));
        }
        $controleIds = $controleIds->unique()->all();

        $notesByControle = [];
        if ($controleIds !== []) {
            Note::where('etudiant_id', $etudiantId)
                ->whereIn('controle_id', $controleIds)
                ->get(['controle_id', 'valeur', 'est_absent'])
                ->each(function ($note) use (&$notesByControle) {
                    $notesByControle[$note->controle_id] = $note;
                });
        }

        $notes = [];

        foreach ($unites as $unite) {
            foreach ($unite->sequences as $sequence) {
                foreach ($sequence->controles as $controle) {
                    $note = $notesByControle[$controle->id] ?? null;
                    $notes[] = [
                        'unite_id' => $unite->id,
                        'unite_nom' => $unite->nom,
                        'unite_coefficient' => $unite->coefficient,
                        'sequence_nom' => $sequence->nom,
                        'controle_id' => $controle->id,
                        'controle_numero' => $controle->numero,
                        'note_max' => $controle->note_max,
                        'valeur' => $note?->valeur,
                        'est_absent' => (bool) ($note?->est_absent ?? false),
                    ];
                }
            }

            // Sequence-free units hold their controls directly on the unite.
            foreach ($unite->controles as $controle) {
                $note = $notesByControle[$controle->id] ?? null;
                $notes[] = [
                    'unite_id' => $unite->id,
                    'unite_nom' => $unite->nom,
                    'unite_coefficient' => $unite->coefficient,
                    'sequence_nom' => $unite->nom,
                    'controle_id' => $controle->id,
                    'controle_numero' => $controle->numero,
                    'note_max' => $controle->note_max,
                    'valeur' => $note?->valeur,
                    'est_absent' => (bool) ($note?->est_absent ?? false),
                ];
            }
        }

        return $notes;
    }

    protected function getBulletin($etudiant, $filiereId, $groupeId, $anneeId)
    {
        $bulletin = $this->bulletinService->calculateBulletin($etudiant->id, $anneeId);
        $mention = $this->bulletinService->getMention($bulletin['moyenne_generale']);

        $semestres = [1 => [], 2 => []];
        foreach ($bulletin['unites'] as $u) {
            $item = [
                'nom' => $u['nom'],
                'coefficient' => $u['coefficient'],
                'sequences' => $u['sequences'],
                'moyenne' => $u['moyenneUnite'],
                'moyenne_cc' => $u['moyenne_cc'],
                'moyenne_theorique' => $u['moyenne_theorique'],
                'moyenne_pratique' => $u['moyenne_pratique'],
            ];

            $semestre = (int) $u['semestre'];
            if ($semestre === 0) {
                $semestres[1][] = $item;
                $semestres[2][] = $item;
                continue;
            }

            if (isset($semestres[$semestre])) {
                $semestres[$semestre][] = $item;
            }
        }

        return [
            'semestres' => $semestres,
            'moyenne_generale' => $bulletin['moyenne_generale'],
            'moyenne_cc' => $bulletin['moyenne_cc'],
            'moyenne_theorique' => $bulletin['moyenne_theorique'],
            'moyenne_pratique' => $bulletin['moyenne_pratique'],
            'nsti' => $bulletin['nsti'],
            'has_soutenance' => $bulletin['has_soutenance'],
            'weights' => $bulletin['weights'],
            'mention' => $mention['label'],
            'decision' => $bulletin['decision'],
        ];
    }
}