<?php

namespace App\Services;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\StageEvaluation;
use App\Models\Unite;

/**
 * Bridges the Stage page (three JSON grids per student) into the ordinary
 * grading tables so the official coefficient 4 of the Stage units actually
 * counts in the averages.
 *
 * Two marks are written, matching the official documents:
 *
 *   grille_evaluation -> control type 'cc'       -> feeds MPCC
 *   grille_examen     -> control type 'pratique' -> feeds MPEFCFP
 *
 * The unit keeps its single coefficient of 4, so Stage is weighted 4 in MPCC
 * and 4 in MPEFCFP. The "garde de nuit" grid is administrative only and never
 * produces a mark.
 */
class StageService
{
    /** Grid max, per the printed documents. */
    private const COMPORTEMENT_MAX = 20;   // 5 criteria x 0-4
    private const APTITUDES_MAX   = 40;   // 10 criteria x 0-4
    private const PRATIQUE_MAX    = 40;   // 2 evaluations x /20
    private const GRILLE_DIVISEUR = 5;    // (score1 + score2) / 5  -> /20

    private const EVALUATION_CONTROL = 'cc';
    private const EXAMEN_CONTROL     = 'pratique';

    /** Barème of the 7 lines of the exam grid; sums to 20. */
    private const EXAMEN_BAREME = [1, 2, 2, 10, 1, 1, 3];

    public function __construct(private MoyenneService $moyenneService) {}

    /**
     * @return array<string,float|null> grille_evaluation / grille_examen -> /20
     */
    public function computeMarks(?array $evaluation, ?array $examen): array
    {
        return [
            'grille_evaluation' => $this->computeEvaluationMark($evaluation),
            'grille_examen'     => $this->computeExamenMark($examen),
        ];
    }

    /**
     * Note Globale = (Score(1) + Score(2)) / 5, on /20.
     *
     * Score(1) = comportement (/20) + aptitudes (/40)
     * Score(2) = 1ère évaluation (/20) + 2ème évaluation (/20)
     */
    public function computeEvaluationMark(?array $data): ?float
    {
        if (!is_array($data)) return null;

        // Distinguish "nothing filled in" from "explicit zeros": a Stage can be
        // legitimately scored 0, and that mark must be recorded.
        $filled = $this->hasEntry($data['comportement'] ?? null)
            || $this->hasEntry($data['aptitudes'] ?? null)
            || $this->hasEntry($data['eval_pratique_1'] ?? null)
            || $this->hasEntry($data['eval_pratique_2'] ?? null);

        if (!$filled) {
            return null;
        }

        $comportement = $this->sumArray($data['comportement'] ?? []);
        $aptitudes   = $this->sumArray($data['aptitudes'] ?? []);
        $pratique1   = $this->num($data['eval_pratique_1'] ?? null);
        $pratique2   = $this->num($data['eval_pratique_2'] ?? null);

        $score1 = $comportement + $aptitudes;
        $score2 = $pratique1 + $pratique2;

        return round(($score1 + $score2) / self::GRILLE_DIVISEUR, 2);
    }

    /**
     * Sum of the 7 exam lines, on /20.
     *
     * Each line is capped at its own barème (the printed form does the same
     * with `max={l.bareme}`), so a mis-typed entry can never push the Stage
     * mark above 20 and inflate the averages.
     */
    public function computeExamenMark(?array $data): ?float
    {
        if (!is_array($data)) return null;

        $lignes = $data['lignes'] ?? null;
        if (!is_array($lignes) || $lignes === []) {
            return null;
        }

        if (!$this->linesFilled($lignes)) {
            return null;
        }

        $total = 0.0;
        foreach (array_values($lignes) as $index => $ligne) {
            $note = $this->num(is_array($ligne) ? ($ligne['note'] ?? null) : $ligne);
            $max = self::EXAMEN_BAREME[$index] ?? 20;
            $total += min($note, (float) $max);
        }

        return round($total, 2);
    }

    /**
     * Copy the stored grids into the Stage unit's controls as ordinary notes.
     *
     * Nothing is written when a grid has not been filled in, so an empty Stage
     * page never injects a silent zero into the averages. Existing notes are
     * updated in place and re-confirmed so they stay protected.
     */
    public function syncForEtudiant(Etudiant $etudiant, int $anneeAcademiqueId): array
    {
        $evaluations = StageEvaluation::where('etudiant_id', $etudiant->id)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->get()
            ->keyBy('type');

        $marks = $this->computeMarks(
            $evaluations['grille_evaluation']->data ?? null,
            $evaluations['grille_examen']->data ?? null,
        );

        return $this->writeMarks($etudiant, $anneeAcademiqueId, $marks);
    }

    /**
     * @param  array<string,float|null>  $marks
     * @return array<string,mixed>
     */
    public function writeMarks(Etudiant $etudiant, int $anneeAcademiqueId, array $marks): array
    {
        $annee = AnneeAcademique::find($anneeAcademiqueId);
        if (!$annee) {
            return ['synced' => [], 'message' => 'Année introuvable.'];
        }

        // An archived year is a closed record: never rewrite its averages.
        if ($annee->is_archived) {
            return [
                'synced'  => $this->syncedMarksForEtudiant($etudiant, $anneeAcademiqueId),
                'message' => "Année {$annee->label} archivée : notes Stage non synchronisées.",
            ];
        }

        $groupe = $etudiant->groupe;
        if (!$groupe || !$groupe->niveau) {
            return ['synced' => [], 'message' => 'Étudiant sans groupe ou niveau.'];
        }

        $unites = Unite::where('nom', 'Stage')
            ->where('filiere_id', $groupe->niveau->filiere_id)
            ->where('numero_annee', $groupe->niveau->numero)
            ->where('is_active', true)
            ->get();

        if ($unites->isEmpty()) {
            return ['synced' => [], 'message' => 'Aucune unité Stage active pour cette filière/année.'];
        }

        $synced = [];

        foreach ($unites as $unite) {
            $controles = Controle::whereHas('sequence', fn($q) => $q->where('unite_id', $unite->id))
                ->get()
                ->keyBy('type');

            $wanted = [
                self::EVALUATION_CONTROL => $marks['grille_evaluation'] ?? null,
                self::EXAMEN_CONTROL     => $marks['grille_examen'] ?? null,
            ];

            foreach ($wanted as $type => $valeur) {
                $controle = $controles->get($type);
                if (!$controle) {
                    continue;
                }

                $label = $this->controleLabel($controle);

                $existing = Note::where('etudiant_id', $etudiant->id)
                    ->where('controle_id', $controle->id)
                    ->first();

                if ($valeur === null) {
                    // Grid cleared: drop the derived note rather than keep a
                    // stale mark that no longer matches the printed grid.
                    if ($existing) {
                        $existing->delete();
                        $synced[$label] = null;
                    }
                    continue;
                }

                if ($existing) {
                    $existing->update([
                        'valeur'       => $valeur,
                        'est_absent'   => false,
                        'is_confirmed' => true,
                        'confirmed_at' => now(),
                    ]);
                } else {
                    Note::create([
                        'etudiant_id'  => $etudiant->id,
                        'controle_id'  => $controle->id,
                        'valeur'       => $valeur,
                        'est_absent'   => false,
                        'is_confirmed' => true,
                        'confirmed_at' => now(),
                    ]);
                }

                $synced[$label] = $valeur;
            }
        }

        return ['synced' => $synced, 'message' => ''];
    }

    /**
     * Marks currently derived from the grids, for display on the Stage page.
     */
    public function syncedMarksForEtudiant(Etudiant $etudiant, int $anneeAcademiqueId): array
    {
        $groupe = $etudiant->groupe;
        if (!$groupe || !$groupe->niveau) return [];

        $unites = Unite::where('nom', 'Stage')
            ->where('filiere_id', $groupe->niveau->filiere_id)
            ->where('numero_annee', $groupe->niveau->numero)
            ->where('is_active', true)
            ->get();

        if ($unites->isEmpty()) return [];

        $sequenceIds = \App\Models\Sequence::whereIn('unite_id', $unites->pluck('id'))->pluck('id');
        $controles = Controle::whereIn('sequence_id', $sequenceIds)->get();

        $notes = Note::where('etudiant_id', $etudiant->id)
            ->whereIn('controle_id', $controles->pluck('id'))
            ->get()
            ->keyBy('controle_id');

        $out = [];
        foreach ($controles as $controle) {
            $note = $notes->get($controle->id);
            $out[$controle->type] = [
                'controle' => $this->controleLabel($controle),
                'valeur'   => $note?->valeur,
            ];
        }

        return $out;
    }

    private function controleLabel(Controle $controle): string
    {
        return $controle->type === self::EXAMEN_CONTROL
            ? 'Examen de stage'
            : 'Évaluation de stage';
    }

    /**
     * True when the field holds an actual entry, including an explicit 0.
     */
    private function hasEntry($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                $v = is_array($v) ? ($v['note'] ?? null) : $v;
                if ($v !== null && $v !== '') {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    private function linesFilled(array $lignes): bool
    {
        foreach ($lignes as $ligne) {
            if ($this->hasEntry(is_array($ligne) ? ($ligne['note'] ?? null) : $ligne)) {
                return true;
            }
        }
        return false;
    }

    private function sumArray($values): float
    {
        if (!is_array($values)) return 0.0;
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += $this->num(is_array($v) ? ($v['note'] ?? null) : $v);
        }
        return $sum;
    }

    private function num($value): float
    {
        if ($value === null || $value === '') return 0.0;
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
