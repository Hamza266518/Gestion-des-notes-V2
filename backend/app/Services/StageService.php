<?php

namespace App\Services;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\StageEvaluation;
use App\Models\StageNote;
use App\Models\Unite;

/**
 * Bridges the Stage page (one JSON grid per service and per student) into the
 * ordinary grading tables so the official coefficient 4 of the Stage units
 * actually counts in the averages.
 *
 * Every Stage unit owns its controls directly (no sequence), one per official
 * service ("Médecine", "Chirurgie", ...). Each service carries two marks:
 *
 *   grille_evaluation -> the service's own note in `notes` (the Évaluation side;
 *                        it can also be typed by hand in the generic grid)
 *   grille_examen     -> a row in `stage_notes` (the Examen side)
 *
 * `notes` is unique on (etudiant_id, controle_id) — one row per student and
 * control is the invariant every read path assumes — so the second mark per
 * service lives in `stage_notes` instead (unique per student, control, type).
 *
 * A Stage unit only counts once every service has BOTH marks; see
 * MoyenneService::stageUniteIncomplete(). The "garde de nuit" grid is
 * administrative only and never produces a mark.
 */
class StageService
{
    /** Grid max, per the printed documents. */
    private const COMPORTEMENT_MAX = 20;   // 5 criteria x 0-4
    private const APTITUDES_MAX   = 40;   // 10 criteria x 0-4
    private const PRATIQUE_MAX    = 40;   // 2 evaluations x /20
    private const GRILLE_DIVISEUR = 5;    // (score1 + score2) / 5  -> /20

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
     * Recompute every stored grid and write its mark to the right service.
     *
     * The grids are stored per service ({ service => grid }); the Évaluation
     * mark goes to the service's note in `notes`, the Examen mark to
     * `stage_notes`. Nothing is written for a grid that has not been filled in,
     * so an empty Stage page never injects a silent zero into the averages.
     */
    public function syncForEtudiant(Etudiant $etudiant, int $anneeAcademiqueId): array
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

        // The controls are the services; match the grid's service key by name.
        $controles = Controle::whereIn('unite_id', $unites->pluck('id'))
            ->get()
            ->keyBy(fn (Controle $c) => $this->serviceKey($c->nom));

        $evaluations = StageEvaluation::where('etudiant_id', $etudiant->id)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->whereIn('type', ['grille_evaluation', 'grille_examen'])
            ->get()
            ->keyBy('type');

        $evalMap = $this->normalizeGridData($evaluations['grille_evaluation']->data ?? null);
        $examMap = $this->normalizeGridData($evaluations['grille_examen']->data ?? null);

        // Évaluation: the service's own note (hand-editable from the grid too).
        foreach ($evalMap as $service => $grid) {
            $controle = $controles->get($this->serviceKey((string) $service));
            if (!$controle) continue;

            $this->writeNote($etudiant, $controle, $this->computeEvaluationMark($grid));
        }

        // Examen: derived, stored in stage_notes.
        $writtenExam = [];
        foreach ($examMap as $service => $grid) {
            $controle = $controles->get($this->serviceKey((string) $service));
            if (!$controle) continue;

            $valeur = $this->computeExamenMark($grid);

            if ($valeur === null) {
                StageNote::where('etudiant_id', $etudiant->id)
                    ->where('controle_id', $controle->id)
                    ->where('type', StageNote::EXAMEN_TYPE)
                    ->delete();
                continue;
            }

            StageNote::updateOrCreate(
                [
                    'etudiant_id' => $etudiant->id,
                    'controle_id' => $controle->id,
                    'type'        => StageNote::EXAMEN_TYPE,
                ],
                ['valeur' => $valeur, 'est_absent' => false]
            );

            $writtenExam[] = $controle->id;
        }

        // A service whose exam grid has been cleared must not keep a stale mark.
        StageNote::where('etudiant_id', $etudiant->id)
            ->whereIn('controle_id', $controles->pluck('id'))
            ->where('type', StageNote::EXAMEN_TYPE)
            ->whereNotIn('controle_id', $writtenExam)
            ->delete();

        return ['synced' => $this->syncedMarksForEtudiant($etudiant, $anneeAcademiqueId), 'message' => ''];
    }

    /**
     * Both marks of every service, for display on the Stage page.
     *
     * @return array<int,array{controle_id:int,numero:int,service:string,evaluation:?float,examen:?float}>
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

        $controles = Controle::whereIn('unite_id', $unites->pluck('id'))
            ->orderBy('unite_id')
            ->orderBy('numero')
            ->get();

        $notes = Note::where('etudiant_id', $etudiant->id)
            ->whereIn('controle_id', $controles->pluck('id'))
            ->get()
            ->keyBy('controle_id');

        $examens = StageNote::where('etudiant_id', $etudiant->id)
            ->whereIn('controle_id', $controles->pluck('id'))
            ->where('type', StageNote::EXAMEN_TYPE)
            ->get()
            ->keyBy('controle_id');

        $out = [];
        foreach ($controles as $controle) {
            $evaluation = $notes->get($controle->id)?->valeur;
            $examen     = $examens->get($controle->id)?->valeur;

            $out[$controle->id] = [
                'controle_id' => $controle->id,
                'numero'      => $controle->numero,
                'service'     => $controle->nom,
                'evaluation'  => $evaluation !== null ? (float) $evaluation : null,
                'examen'      => $examen !== null ? (float) $examen : null,
            ];
        }

        return $out;
    }

    /**
     * Write (or clear) a service's Évaluation note in `notes`, keeping the
     * derived marks protected as confirmed.
     */
    private function writeNote(Etudiant $etudiant, Controle $controle, ?float $valeur): void
    {
        $existing = Note::where('etudiant_id', $etudiant->id)
            ->where('controle_id', $controle->id)
            ->first();

        if ($valeur === null) {
            // Grid cleared: drop the derived note rather than keep a stale mark
            // that no longer matches the printed grid.
            if ($existing) {
                $existing->delete();
            }
            return;
        }

        if ($existing) {
            $existing->update([
                'valeur'       => $valeur,
                'est_absent'   => false,
                'is_confirmed' => true,
                'confirmed_at' => now(),
            ]);
            return;
        }

        Note::create([
            'etudiant_id'  => $etudiant->id,
            'controle_id'  => $controle->id,
            'valeur'       => $valeur,
            'est_absent'   => false,
            'is_confirmed' => true,
            'confirmed_at' => now(),
        ]);
    }

    /**
     * The grids are stored as { service => grid }. Older sheets stored a single
     * grid (one service) with the scoring fields at the top level; normalize
     * both shapes to the keyed map so a saved sheet keeps working.
     */
    private function normalizeGridData(?array $data): array
    {
        if (!is_array($data)) return [];

        if (!$this->looksLikeSingleGrid($data)) {
            return $data;
        }

        $service = is_string($data['service'] ?? null) ? trim($data['service']) : '';
        if ($service === '') return [];

        return [$service => $data];
    }

    /** True when the array holds the scoring fields of one grid sheet. */
    private function looksLikeSingleGrid(array $data): bool
    {
        foreach (['comportement', 'aptitudes', 'eval_pratique_1', 'eval_pratique_2', 'lignes', 'pratique_executee'] as $key) {
            if (array_key_exists($key, $data)) {
                return true;
            }
        }

        return false;
    }

    /** Normalize a service name for matching grid keys to controls. */
    private function serviceKey(string $nom): string
    {
        return mb_strtolower(trim($nom));
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