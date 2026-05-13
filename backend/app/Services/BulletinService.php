<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Unite;

class BulletinService
{
    const WEIGHT_CC = 0.3667;
    const WEIGHT_THEORIQUE = 0.2666;
    const WEIGHT_PRATIQUE = 0.3667;

    public function __construct(
        private MoyenneService $moyenneService
    ) {}

    public function calculateBulletin(int $etudiantId, int $anneeAcademiqueId, ?int $semestre = null): array
    {
        $etudiant = Etudiant::with('groupe.niveau.filiere')->findOrFail($etudiantId);
        $filiereId = $etudiant->groupe->niveau->filiere_id;
        $niveauNumero = $etudiant->groupe->niveau->numero;

        $unites = Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $niveauNumero)
            ->when($semestre !== null, fn($q) => $q->where('semestre', $semestre))
            ->where('is_active', true)
            ->with(['sequences.controles.notes' => fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('annee_academique_id', $anneeAcademiqueId))])
            ->orderBy('ordre')
            ->get();

        $examens = Examen::where('etudiant_id', $etudiantId)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->get()
            ->groupBy('unite_id');

        $unitesData = [];
        $totalCoefCC = 0;
        $totalCoefTheo = 0;
        $totalCoefPra = 0;
        $totalWeightedCC = 0;
        $totalWeightedTheo = 0;
        $totalWeightedPra = 0;

        foreach ($unites as $unite) {
            $uniteCoef = $unite->coefficient;

            $uniteCC = $this->moyenneTypeForUnite($etudiantId, $unite, 'cc');

            $examenTheo = $examens->get($unite->id)?->where('type', 'theorique')->avg('valeur');
            $examenPra = $examens->get($unite->id)?->where('type', 'pratique')->avg('valeur');

            $controleTheo = $this->moyenneTypeForUnite($etudiantId, $unite, 'theorique');
            $uniteTheo = match (true) {
                $examenTheo !== null && $controleTheo !== null => round(($examenTheo + $controleTheo) / 2, 2),
                $examenTheo !== null => $examenTheo,
                default => $controleTheo,
            };
            $controlePra = $this->moyenneTypeForUnite($etudiantId, $unite, 'pratique');
            $unitePra = match (true) {
                $examenPra !== null && $controlePra !== null => round(($examenPra + $controlePra) / 2, 2),
                $examenPra !== null => $examenPra,
                default => $controlePra,
            };

            if ($uniteCC !== null) {
                $totalWeightedCC += $uniteCC * $uniteCoef;
                $totalCoefCC += $uniteCoef;
            }
            if ($uniteTheo !== null) {
                $totalWeightedTheo += $uniteTheo * $uniteCoef;
                $totalCoefTheo += $uniteCoef;
            }
            if ($unitePra !== null) {
                $totalWeightedPra += $unitePra * $uniteCoef;
                $totalCoefPra += $uniteCoef;
            }

            $sequencesData = [];
            foreach ($unite->sequences as $seq) {
                $controlesData = [];
                foreach ($seq->controles as $ctrl) {
                    $note = $ctrl->notes->firstWhere('etudiant_id', $etudiantId);
                    $controlesData[] = [
                        'id' => $ctrl->id,
                        'numero' => $ctrl->numero,
                        'type' => $ctrl->type,
                        'nom' => $ctrl->nom,
                        'valeur' => $note?->valeur,
                    ];
                }
                $sequencesData[] = [
                    'id' => $seq->id,
                    'nom' => $seq->nom,
                    'coefficient' => $seq->coefficient,
                    'controles' => $controlesData,
                    'moyenneSeq' => $this->moyenneService->moyenneSequence($etudiantId, $seq->id),
                ];
            }

            $moyenneUnite = $this->moyenneService->moyenneUnite($etudiantId, $unite->id);

            $unitesData[] = [
                'id' => $unite->id,
                'nom' => $unite->nom,
                'coefficient' => $uniteCoef,
                'semestre' => $unite->semestre,
                'sequences' => $sequencesData,
                'moyenneUnite' => $moyenneUnite,
                'moyenne_cc' => $uniteCC,
                'moyenne_theorique' => $uniteTheo,
                'moyenne_pratique' => $unitePra,
            ];
        }

        $moyenneCC = $totalCoefCC > 0 ? round($totalWeightedCC / $totalCoefCC, 2) : null;
        $moyenneTheo = $totalCoefTheo > 0 ? round($totalWeightedTheo / $totalCoefTheo, 2) : null;
        $moyennePra = $totalCoefPra > 0 ? round($totalWeightedPra / $totalCoefPra, 2) : null;

        $moyenneGenerale = null;
        if ($moyenneCC !== null || $moyenneTheo !== null || $moyennePra !== null) {
            $moyenneGenerale = 0;
            if ($moyenneCC !== null) $moyenneGenerale += $moyenneCC * self::WEIGHT_CC;
            if ($moyenneTheo !== null) $moyenneGenerale += $moyenneTheo * self::WEIGHT_THEORIQUE;
            if ($moyennePra !== null) $moyenneGenerale += $moyennePra * self::WEIGHT_PRATIQUE;
            $moyenneGenerale = round($moyenneGenerale, 2);
        }

        $decision = null;
        if ($moyenneGenerale !== null) {
            $decision = $moyenneGenerale >= 10 ? 'Admis(e)' : 'Redoublant(e)';
        }

        return [
            'student' => $etudiant,
            'unites' => $unitesData,
            'moyenne_cc' => $moyenneCC,
            'moyenne_theorique' => $moyenneTheo,
            'moyenne_pratique' => $moyennePra,
            'moyenne_generale' => $moyenneGenerale,
            'decision' => $decision,
            'total_coef_cc' => $totalCoefCC,
            'total_coef_theorique' => $totalCoefTheo,
            'total_coef_pratique' => $totalCoefPra,
        ];
    }

    private function moyenneTypeForUnite(int $etudiantId, Unite $unite, string $type): ?float
    {
        $totalNote = 0;
        $totalCoef = 0;

        foreach ($unite->sequences as $seq) {
            $seqAvg = $this->moyenneTypeForSequence($etudiantId, $seq, $type);
            if ($seqAvg !== null) {
                $totalNote += $seqAvg * $seq->coefficient;
                $totalCoef += $seq->coefficient;
            }
        }

        if ($totalCoef === 0) return null;
        return round($totalNote / $totalCoef, 2);
    }

    private function moyenneTypeForSequence(int $etudiantId, $sequence, string $type): ?float
    {
        $controlesOfType = $sequence->controles->where('type', $type);
        $notes = [];

        foreach ($controlesOfType as $ctrl) {
            $note = $ctrl->notes->firstWhere('etudiant_id', $etudiantId);
            if ($note && $note->valeur !== null) {
                $notes[] = (float) $note->valeur;
            }
        }

        if (empty($notes)) return null;
        return round(array_sum($notes) / count($notes), 2);
    }

    public function getMention(?float $moyenne): array
    {
        if ($moyenne === null) {
            return ['label' => '—', 'color' => 'gray'];
        }

        if ($moyenne >= 16) return ['label' => 'Très Bien', 'color' => 'teal'];
        if ($moyenne >= 14) return ['label' => 'Bien', 'color' => 'blue'];
        if ($moyenne >= 12) return ['label' => 'Assez Bien', 'color' => 'green'];
        if ($moyenne >= 10) return ['label' => 'Passable', 'color' => 'yellow'];
        return ['label' => 'Insuffisant', 'color' => 'red'];
    }
}
