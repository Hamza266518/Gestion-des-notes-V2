<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\StageNote;
use App\Models\Unite;

class MoyenneService
{
    public function moyenneSequence(int $etudiantId, int $sequenceId, ?int $anneeAcademiqueId = null): ?float
    {
        $sequence = \App\Models\Sequence::with('controles')->findOrFail($sequenceId);
        $notes    = [];

        // `$anneeAcademiqueId` is accepted for call-site compatibility but no longer
        // filters. `notes` carries no year column, and this filter was expressed as
        // "the student is currently enrolled in that year" -- which erased the grades
        // of any student who had since been promoted to the next year. The caller
        // already pins the year: BulletinService reaches here only through units of
        // the group resolved for the requested year, so the sequence's controls are
        // the right ones.
        foreach ($sequence->controles as $controle) {
            $note = \App\Models\Note::where('etudiant_id', $etudiantId)
                ->where('controle_id', $controle->id)
                ->first();
            if ($note && $note->valeur !== null) $notes[] = (float) $note->valeur;
        }

        if (empty($notes)) return null;
        return round(array_sum($notes) / count($notes), 2);
    }

    public function moyenneUnite(int $etudiantId, int $uniteId, ?int $anneeAcademiqueId = null): ?float
    {
        // MPCC rule: every controle of the unite counts as a plain "Contrôle",
        // the sum is divided by the total number of controles configured, and a
        // control without an entered note counts as zero. The Stage units are
        // sequence-free: their controls hang directly off the unite.
        // null == "no note anywhere in the unite yet".
        $unite = \App\Models\Unite::with('sequences.controles.notes', 'controles.notes')->findOrFail($uniteId);

        // A Stage unit is only counted once every service has its two marks.
        if ($this->stageUniteIncomplete($unite, $etudiantId)) {
            return null;
        }

        // Stage units: the average of (Évaluation + Examen) over every service.
        // The Évaluation side lives in `notes`, the Examen side in `stage_notes`.
        if ($unite->nom === 'Stage' && $unite->controles->isNotEmpty()) {
            $controleIds = $unite->controles->pluck('id');

            $evaluations = \App\Models\Note::where('etudiant_id', $etudiantId)
                ->whereIn('controle_id', $controleIds)
                ->pluck('valeur')
                ->map(fn ($v) => (float) $v);

            $examens = StageNote::where('etudiant_id', $etudiantId)
                ->whereIn('controle_id', $controleIds)
                ->where('type', StageNote::EXAMEN_TYPE)
                ->pluck('valeur')
                ->map(fn ($v) => (float) $v);

            $marks = $evaluations->concat($examens)->all();
            $total = $controleIds->count() * 2;

            if ($total === 0 || count($marks) < $total) return null;

            return round(array_sum($marks) / $total, 2);
        }

        $notes = [];
        $totalControles = 0;

        $controles = collect();
        foreach ($unite->sequences as $sequence) {
            $controles = $controles->merge($sequence->controles);
        }
        $controles = $controles->merge($unite->controles);

        foreach ($controles as $controle) {
            $totalControles++;
            $note = $controle->notes->firstWhere('etudiant_id', $etudiantId);
            if ($note && $note->valeur !== null) {
                $notes[] = (float) $note->valeur;
            }
        }

        if (empty($notes)) return null;
        return round(array_sum($notes) / max($totalControles, 1), 2);
    }

    /**
     * A Stage unit is sequence-free and its own controls are the services. It is
     * only counted once every service carries BOTH its marks: the Évaluation
     * note (in `notes`) and the Examen mark (in `stage_notes`). While any mark
     * is missing, the unit is excluded from the averages instead of being
     * dragged down by implicit zeros. Non-Stage units are never gated.
     */
    public function stageUniteIncomplete(Unite $unite, int $etudiantId): bool
    {
        if ($unite->nom !== 'Stage' || $unite->controles->isEmpty()) {
            return false;
        }

        $controleIds = $unite->controles->pluck('id');

        $evaluations = \App\Models\Note::where('etudiant_id', $etudiantId)
            ->whereIn('controle_id', $controleIds)
            ->pluck('valeur', 'controle_id');

        $examens = StageNote::where('etudiant_id', $etudiantId)
            ->whereIn('controle_id', $controleIds)
            ->where('type', StageNote::EXAMEN_TYPE)
            ->pluck('valeur', 'controle_id');

        foreach ($controleIds as $id) {
            if (($evaluations[$id] ?? null) === null || ($examens[$id] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Stage's MPCC value: the average of its services' Évaluation marks
     * (the Évaluation grids, stored in `notes`). The Examen side feeds the
     * Pratique average instead (see moyenneStageExamen()).
     *
     * There is no completeness gate, so the CC sheet shows the value as soon as
     * a service is graded; a service without an Évaluation mark is not counted.
     */
    public function moyenneStageEvaluation(int $etudiantId, Unite $unite): ?float
    {
        if ($unite->nom !== 'Stage') return null;

        $controleIds = $unite->controles->pluck('id');
        if ($controleIds->isEmpty()) return null;

        $evaluations = \App\Models\Note::where('etudiant_id', $etudiantId)
            ->whereIn('controle_id', $controleIds)
            ->whereNotNull('valeur')
            ->pluck('valeur');

        if ($evaluations->isEmpty()) return null;

        return round($evaluations->sum() / $evaluations->count(), 2);
    }

    /**
     * The Stage's Pratique (MPEFCFP) value: the average of its services' Examen
     * marks (the Examen grids, stored in `stage_notes`). Mirrors
     * moyenneStageEvaluation(): no completeness gate, a service without an
     * Examen mark is not counted.
     */
    public function moyenneStageExamen(int $etudiantId, Unite $unite): ?float
    {
        if ($unite->nom !== 'Stage') return null;

        $controleIds = $unite->controles->pluck('id');
        if ($controleIds->isEmpty()) return null;

        $examens = StageNote::where('etudiant_id', $etudiantId)
            ->whereIn('controle_id', $controleIds)
            ->where('type', StageNote::EXAMEN_TYPE)
            ->whereNotNull('valeur')
            ->pluck('valeur');

        if ($examens->isEmpty()) return null;

        return round($examens->sum() / $examens->count(), 2);
    }

    public function moyenneGenerale(int $etudiantId, ?int $semestre, int $anneeAcademiqueId): ?float
    {
        $etudiant = Etudiant::with('groupe.niveau')->findOrFail($etudiantId);

        // Same rule as BulletinService: the year's curriculum comes from the group
        // belonging to that year, so a promoted student's old-year average survives.
        $groupe = $etudiant->groupe;
        if (!$groupe || !$groupe->niveau) return null;

        if ((int) $groupe->annee_academique_id !== $anneeAcademiqueId) {
            $groupe = Groupe::where('annee_academique_id', $anneeAcademiqueId)
                ->where('niveau_id', $groupe->niveau_id)
                ->first();
            if (!$groupe || !$groupe->niveau) return null;
        }

        $filiereId = $groupe->niveau->filiere_id;

        $unites = Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $groupe->niveau->numero)
            ->when($semestre !== null, fn($q) => $q->where('semestre', $semestre))
            ->where('is_active', true)
            ->where('nom', '!=', SoutenanceService::UNITE_NOM)
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
}
