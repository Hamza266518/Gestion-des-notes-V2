<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Groupe;
use App\Models\StageNote;
use App\Models\Unite;
use App\Support\Curriculum\OfficialCurriculum;

class BulletinService
{
    const WEIGHT_CC = 0.3667;
    const WEIGHT_THEORIQUE = 0.2666;
    const WEIGHT_PRATIQUE = 0.3667;

    public function __construct(
        private MoyenneService $moyenneService,
        private SoutenanceService $soutenanceService
    ) {}

    /**
     * Weights of the components of the final mark.
     *
     * A programme whose final year ends with a soutenance uses the official
     * formula 30% MPCC / 20% MPEFCFT / 30% MPEFCFP / 20% NSTI. Every other
     * programme keeps the historical 36,67 / 26,66 / 36,67 split.
     *
     * @return array<string,float> keyed by cc / theorique / pratique (and nsti)
     */
    public static function componentWeights(bool $hasSoutenance): array
    {
        if ($hasSoutenance) {
            return [
                'cc'       => OfficialCurriculum::FINAL_WEIGHTS['MPCC'],
                'theorique' => OfficialCurriculum::FINAL_WEIGHTS['MPEFCFT'],
                'pratique' => OfficialCurriculum::FINAL_WEIGHTS['MPEFCFP'],
                'nsti'     => OfficialCurriculum::FINAL_WEIGHTS['NSTI'],
            ];
        }

        return [
            'cc'       => self::WEIGHT_CC,
            'theorique' => self::WEIGHT_THEORIQUE,
            'pratique' => self::WEIGHT_PRATIQUE,
        ];
    }

    /**
     * The group whose curriculum describes `$anneeAcademiqueId`, not necessarily
     * the group the student is enrolled in today.
     *
     * `etudiants.annee_academique_id` means "currently enrolled", and ProgressionController
     * overwrites it when a student is promoted. The units of a year are keyed by
     * `filiere_id + numero_annee`, so the academic year a transcript belongs to is
     * decided by the group, which is stable: a promoted student's old group still
     * points at the old year.
     *
     * Redoublants re-sit the same `numero_annee`, so they are matched by niveau in the
     * requested year. That is the same curve as any other repeat, and the
     * (etudiant_id, controle_id) unique index means the newer grade replaces the older
     * one. Re-sitting a year is therefore not historised at the note level -- inherent to
     * the current schema, not something this resolution can recover.
     */
    private function resolveGroupe(Etudiant $etudiant, int $anneeAcademiqueId): ?Groupe
    {
        $groupe = $etudiant->groupe;
        if (!$groupe) return null;

        if ((int) $groupe->annee_academique_id === $anneeAcademiqueId) {
            return $groupe;
        }

        return Groupe::where('annee_academique_id', $anneeAcademiqueId)
            ->where('niveau_id', $groupe->niveau_id)
            ->first();
    }

    /**
     * A transcript for a year the student was never enrolled in: shaped like a
     * normal result so callers can read the same keys, with no average.
     */
    private function emptyBulletin(Etudiant $etudiant): array
    {
        return [
            'student' => $etudiant,
            'unites' => [],
            'moyenne_cc' => null,
            'moyenne_theorique' => null,
            'moyenne_pratique' => null,
            'nsti' => null,
            'has_soutenance' => false,
            'weights' => self::componentWeights(false),
            'moyenne_generale' => null,
            'decision' => null,
            'total_coef_cc' => 0,
            'total_coef_theorique' => 0,
            'total_coef_pratique' => 0,
        ];
    }

    public function calculateBulletin(int $etudiantId, int $anneeAcademiqueId, ?int $semestre = null): array
    {
        $etudiant = Etudiant::with('groupe.niveau.filiere')->findOrFail($etudiantId);

        $groupe = $this->resolveGroupe($etudiant, $anneeAcademiqueId);
        if (!$groupe || !$groupe->niveau) {
            return $this->emptyBulletin($etudiant);
        }

        $filiereId = $groupe->niveau->filiere_id;
        $niveauNumero = $groupe->niveau->numero;

        // No year filter on notes: `notes` has no annee_academique_id column. The
        // units below already pin the year via numero_annee, and firstWhere()
        // narrows to this student, so filtering by the student's current enrolment
        // only erased the grades of anyone who had since been promoted.
        $unites = Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $niveauNumero)
            ->when($semestre !== null, fn($q) => $q->where('semestre', $semestre))
            ->where('is_active', true)
            ->where('nom', '!=', SoutenanceService::UNITE_NOM)
            ->with('sequences.controles.notes', 'controles.notes')
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

            $uniteTheo = $examenTheo;
            $unitePra = $examenPra;

            // The Stage keeps its practical marks in `stage_notes` (one Examen
            // grid per service), not in the `examens` table. Its Pratique
            // component is the average of those per-service Examen marks.
            if ($unite->nom === 'Stage') {
                $unitePra = $this->moyenneService->moyenneStageExamen($etudiantId, $unite);
            }

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
                        'nom' => 'C' . $ctrl->numero,
                        'note_max' => $ctrl->note_max,
                        'valeur' => $note?->valeur,
                        'est_absent' => (bool) ($note?->est_absent ?? false),
                    ];
                }
                $sequencesData[] = [
                    'id' => $seq->id,
                    'nom' => $seq->nom,
                    'controles' => $controlesData,
                    'moyenneSeq' => $this->moyenneService->moyenneSequence($etudiantId, $seq->id, $anneeAcademiqueId),
                ];
            }

            // Sequence-free units (the Stage) hold their controls directly on the
            // unite. Expose them as one display row so the transcript can list the
            // N controls, exactly as a normal unit lists its sequences' controls.
            if ($unite->sequences->isEmpty() && $unite->controles->isNotEmpty()) {
                // A Stage unit carries two marks per service: the Évaluation
                // note in `notes` and the Examen mark in `stage_notes`. Both
                // feed the row average, and both are required before it counts.
                $examMarks = $unite->nom === 'Stage'
                    ? StageNote::where('etudiant_id', $etudiantId)
                        ->whereIn('controle_id', $unite->controles->pluck('id'))
                        ->where('type', StageNote::EXAMEN_TYPE)
                        ->pluck('valeur', 'controle_id')
                    : collect();

                $controlesData = [];
                $entered = [];
                foreach ($unite->controles as $ctrl) {
                    $note = $ctrl->notes->firstWhere('etudiant_id', $etudiantId);
                    if ($note && $note->valeur !== null) {
                        $entered[] = (float) $note->valeur;
                    }
                    if (($examMarks[$ctrl->id] ?? null) !== null) {
                        $entered[] = (float) $examMarks[$ctrl->id];
                    }
                    $controlesData[] = [
                        'id' => $ctrl->id,
                        'numero' => $ctrl->numero,
                        'nom' => $ctrl->nom ?? ('C' . $ctrl->numero),
                        'note_max' => $ctrl->note_max,
                        'valeur' => $note?->valeur,
                        'est_absent' => (bool) ($note?->est_absent ?? false),
                    ];
                }

                // The Stage row shows the average of every mark entered so far
                // (both the Évaluation and the Examen side); it is blank only
                // until the first mark is written.
                $sequencesData[] = [
                    'id' => null,
                    'nom' => $unite->nom,
                    'coefficient' => null,
                    'controles' => $controlesData,
                    'moyenneSeq' => $entered === []
                        ? null
                        : round(array_sum($entered) / count($entered), 2),
                ];
            }

            // MPCC and the unit average are now the same figure: sum of every
            // controle of every sequence, divided by the total configured.
            $moyenneUnite = $uniteCC;

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

        $hasSoutenance = $semestre === null
            && $this->soutenanceService->aSoutenance($filiereId, $niveauNumero);
        $nsti = $hasSoutenance
            ? $this->soutenanceService->moyenne($etudiantId, $filiereId, $niveauNumero)
            : null;

        $weights = self::componentWeights($hasSoutenance);

        $moyenneGenerale = null;
        if ($moyenneCC !== null || $moyenneTheo !== null || $moyennePra !== null || $nsti !== null) {
            $components = [
                'cc'       => $moyenneCC,
                'theorique' => $moyenneTheo,
                'pratique' => $moyennePra,
                'nsti'     => $nsti,
            ];

            // A component with no mark yet is left out and the remaining
            // weights are rescaled over their own total, so a student is never
            // dragged down by a mark that was simply not entered.
            $totalAvailable = 0.0;
            foreach ($weights as $key => $weight) {
                if ($components[$key] !== null) {
                    $totalAvailable += $weight;
                }
            }

            $applied = $weights;
            if ($totalAvailable > 0 && abs($totalAvailable - 1) > 0.0001) {
                $scale = 1 / $totalAvailable;
                foreach ($applied as $key => $weight) {
                    $applied[$key] = $components[$key] !== null ? $weight * $scale : 0.0;
                }
            }

            $moyenneGenerale = 0.0;
            foreach ($applied as $key => $weight) {
                if ($components[$key] !== null) {
                    $moyenneGenerale += $components[$key] * $weight;
                }
            }
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
            'nsti' => $nsti,
            'has_soutenance' => $hasSoutenance,
            'weights' => self::componentWeights($hasSoutenance),
            'moyenne_generale' => $moyenneGenerale,
            'decision' => $decision,
            'total_coef_cc' => $totalCoefCC,
            'total_coef_theorique' => $totalCoefTheo,
            'total_coef_pratique' => $totalCoefPra,
        ];
    }

    private function moyenneTypeForUnite(int $etudiantId, Unite $unite, string $type): ?float
    {
        // MPCC rule: every controle of the unite counts as a plain "Contrôle",
        // the sum is divided by the total controles configured, and a missing
        // note counts as zero. "Théorique" and "Pratique" at unite level come
        // exclusively from examens now, so those averages only appear as null.
        if ($type !== 'cc') return null;

        // A Stage unit carries two marks per service: the Évaluation (the CC
        // side, in `notes`) and the Examen (the Pratique side, in `stage_notes`,
        // wired in calculateBulletin). Its CC is the average of the Évaluation
        // marks entered so far; a service still ungraded does not blank it.
        if ($unite->nom === 'Stage') {
            return $this->moyenneService->moyenneStageEvaluation($etudiantId, $unite);
        }

        $notes = [];
        $totalControles = 0;

        $controles = collect();
        foreach ($unite->sequences as $seq) {
            $controles = $controles->merge($seq->controles);
        }
        $controles = $controles->merge($unite->controles);

        foreach ($controles as $ctrl) {
            $totalControles++;
            $note = $ctrl->notes->firstWhere('etudiant_id', $etudiantId);
            if ($note && $note->valeur !== null) {
                $notes[] = (float) $note->valeur;
            }
        }

        if (empty($notes)) return null;
        return round(array_sum($notes) / max($totalControles, 1), 2);
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
