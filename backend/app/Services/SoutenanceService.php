<?php

namespace App\Services;

use App\Models\Unite;
use App\Support\Curriculum\OfficialCurriculum;

/**
 * The NSTI (note de soutenance) of the programmes whose final year ends with a
 * jury presentation.
 *
 * A "Soutenance" unit, sequence-free like the Stage, holds the ten jury criteria
 * as its direct controls (numbered 1..10 in the order of
 * OfficialCurriculum::NSTI_CRITERIA). The admin grades them through the ordinary
 * "Saisie des Notes" screen; nothing is derived from a grid.
 *
 * The unit itself is never counted as a MPCC / théorique / pratique unit: it is
 * only ever read here, to produce the NSTI component of the final average.
 */
class SoutenanceService
{
    public const UNITE_NOM = 'Soutenance';

    /**
     * Programme/year pairs that have a soutenance: the final year of each
     * programme (AS an1, IA an2, IP an3).
     */
    public const PROGRAMMES = [
        ['code' => 'AS', 'annee' => 1],
        ['code' => 'IA', 'annee' => 2],
        ['code' => 'IP', 'annee' => 3],
    ];

    /**
     * The active soutenance unit of a filière/année, its criteria loaded, or
     * null when the programme has none.
     */
    public function unitePour(int $filiereId, int $numeroAnnee): ?Unite
    {
        return Unite::where('nom', self::UNITE_NOM)
            ->where('filiere_id', $filiereId)
            ->where('numero_annee', $numeroAnnee)
            ->where('is_active', true)
            ->with('controles.notes')
            ->first();
    }

    public function aSoutenance(int $filiereId, int $numeroAnnee): bool
    {
        return Unite::where('nom', self::UNITE_NOM)
            ->where('filiere_id', $filiereId)
            ->where('numero_annee', $numeroAnnee)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * NSTI = coefficient-weighted mean of the criteria the jury has graded.
     *
     * A criterion with no note is left out, and the mean is taken over the
     * coefficients actually present -- the same rule every recap table applies,
     * so the relevé and the bulletin always agree. null when the programme has
     * no soutenance unit, or nothing is graded yet.
     */
    public function moyenne(int $etudiantId, int $filiereId, int $numeroAnnee): ?float
    {
        $unite = $this->unitePour($filiereId, $numeroAnnee);
        if (!$unite) {
            return null;
        }

        $controles = $unite->controles->keyBy('numero');

        $weighted = 0.0;
        $coefTotal = 0;

        foreach (OfficialCurriculum::NSTI_CRITERIA as $index => $criterion) {
            $controle = $controles->get($index + 1);
            $note = $controle?->notes->firstWhere('etudiant_id', $etudiantId);

            if (!$note || $note->valeur === null) {
                continue;
            }

            $weighted += (float) $note->valeur * $criterion['coefficient'];
            $coefTotal += $criterion['coefficient'];
        }

        return $coefTotal > 0 ? round($weighted / $coefTotal, 2) : null;
    }
}
