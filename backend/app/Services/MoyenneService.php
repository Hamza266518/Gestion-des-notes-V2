<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\Unite;

class MoyenneService
{
    public function moyenneSequence(int $etudiantId, int $sequenceId, ?string $controleType = null, ?int $anneeAcademiqueId = null): ?float
    {
        $sequence = \App\Models\Sequence::with('controles')->findOrFail($sequenceId);
        $notes    = [];

        foreach ($sequence->controles as $controle) {
            if ($controleType !== null && $controle->type !== $controleType) continue;
            $query = \App\Models\Note::where('etudiant_id', $etudiantId)
                ->where('controle_id', $controle->id);
            if ($anneeAcademiqueId !== null) {
                $query->whereHas('etudiant', fn($q) => $q->where('annee_academique_id', $anneeAcademiqueId));
            }
            $note = $query->first();
            if ($note) $notes[] = $note->valeur;
        }

        if (empty($notes)) return null;
        return round(array_sum($notes) / count($notes), 2);
    }

    public function moyenneUnite(int $etudiantId, int $uniteId, ?string $controleType = null, ?int $anneeAcademiqueId = null): ?float
    {
        $unite      = \App\Models\Unite::with('sequences')->findOrFail($uniteId);
        $totalPoids = 0;
        $totalNote  = 0;

        foreach ($unite->sequences as $sequence) {
            $moy = $this->moyenneSequence($etudiantId, $sequence->id, $controleType, $anneeAcademiqueId);
            if ($moy !== null) {
                $totalNote  += $moy * $sequence->coefficient;
                $totalPoids += $sequence->coefficient;
            }
        }

        if ($totalPoids === 0) return null;
        return round($totalNote / $totalPoids, 2);
    }

    public function moyenneGenerale(int $etudiantId, ?int $semestre, int $anneeAcademiqueId): ?float
    {
        $etudiant = Etudiant::findOrFail($etudiantId);
        $filiereId = $etudiant->groupe->niveau->filiere_id;

        $unites = Unite::where('filiere_id', $filiereId)
            ->when($semestre !== null, fn($q) => $q->where('semestre', $semestre))
            ->where('is_active', true)
            ->get();

        $totalPoids = 0;
        $totalNote  = 0;

        foreach ($unites as $unite) {
            $moy = $this->moyenneUnite($etudiantId, $unite->id, $anneeAcademiqueId);
            if ($moy !== null) {
                $totalNote  += $moy * $unite->coefficient;
                $totalPoids += $unite->coefficient;
            }
        }

        if ($totalPoids === 0) return null;
        return round($totalNote / $totalPoids, 2);
    }

    public function getMention(float $moyenne): string
    {
        if ($moyenne >= 16) return 'Très Bien';
        if ($moyenne >= 14) return 'Bien';
        if ($moyenne >= 12) return 'Assez Bien';
        if ($moyenne >= 10) return 'Passable';
        return 'Insuffisant';
    }
}