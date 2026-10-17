<?php

namespace App\Services;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\SemestrePublication;

/**
 * Single source of truth for "may this grade still be written?".
 *
 * The rule is per group: a grade is closed only when a publication is live for
 * the group the student actually belongs to. An earlier version asked the
 * question at niveau level -- "is any group at this level published?" -- which
 * meant publishing one cohort locked its siblings, and an instructor was
 * blocked from a grade the admin could still edit from the same screen.
 *
 * Semester handling is unchanged: a `bulletin` covers both semesters, while
 * `notes_s1` only closes semester 1 and `notes_s2` only semester 2.
 */
class PublicationLockService
{
    /**
     * Per-request memo, keyed by the unit a control belongs to. Every control of
     * a sequence shares one unité, so the scanner's "status of all my controls"
     * call resolves one query per sequence instead of one per control.
     *
     * @var array<string, array<int>>
     */
    private array $memoVerrouilles = [];

    /**
     * @var array<string, \Illuminate\Support\Collection>
     */
    private array $memoGroupes = [];

    /**
     * Memoised "is there a current year at all?".
     *
     * Every lock below is scoped to the current year. When there is none the
     * year-scoped lookup resolves to "nothing published", which read as
     * "unlocked" and silently reopened published grades for the whole school
     * during the window between archiving a year and switching to the next one.
     * Callers fail closed instead.
     */
    private ?bool $memoHasCurrentAnnee = null;

    private function hasCurrentAnnee(): bool
    {
        if ($this->memoHasCurrentAnnee === null) {
            $this->memoHasCurrentAnnee = AnneeAcademique::where('is_current', true)->exists();
        }

        return $this->memoHasCurrentAnnee;
    }

    /**
     * Publication types that close a control, given the control's semester.
     */
    public function typesPour(int $semestre): array
    {
        return $semestre === 1
            ? ['notes_s1', 'bulletin']
            : ['notes_s2', 'bulletin'];
    }

    private function signature(Controle $controle): ?string
    {
        $unite = $controle->sequence?->unite;
        if (!$unite) return null;

        return $unite->filiere_id . '|' . $unite->numero_annee . '|' . (int) $unite->semestre;
    }

    /**
     * Groups a control's students may belong to (its filière/année level),
     * for the current academic year.
     */
    public function groupesDuControle(Controle $controle): \Illuminate\Support\Collection
    {
        $unite = $controle->sequence?->unite;
        if (!$unite) return collect();

        $sig = $unite->filiere_id . '|' . $unite->numero_annee;
        if (array_key_exists($sig, $this->memoGroupes)) {
            return $this->memoGroupes[$sig];
        }

        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) return $this->memoGroupes[$sig] = collect();

        $currentAnnee = AnneeAcademique::where('is_current', true)->first();
        if (!$currentAnnee) return $this->memoGroupes[$sig] = collect();

        return $this->memoGroupes[$sig] = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $currentAnnee->id)
            ->pluck('id');
    }

    /**
     * Ids of those groups whose results are already published.
     */
    public function groupesVerrouilles(Controle $controle): array
    {
        $unite = $controle->sequence?->unite;
        if (!$unite) return [];

        $sig = $this->signature($controle);
        if ($sig !== null && array_key_exists($sig, $this->memoVerrouilles)) {
            return $this->memoVerrouilles[$sig];
        }

        $verrouilles = [];

        $groupeIds = $this->groupesDuControle($controle);
        $currentAnnee = AnneeAcademique::where('is_current', true)->first();

        if ($groupeIds->isNotEmpty() && $currentAnnee) {
            // distinct() matters: a group can carry several published types at
            // once (notes_s1 plus bulletin). Without it the same groupe_id comes
            // back twice and the count comparison in verrouilleTout() fails.
            $verrouilles = SemestrePublication::whereIn('groupe_id', $groupeIds)
                ->where('annee_academique_id', $currentAnnee->id)
                ->whereIn('type', $this->typesPour((int) $unite->semestre))
                ->where('is_published', true)
                ->distinct()
                ->pluck('groupe_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($sig !== null) {
            $this->memoVerrouilles[$sig] = $verrouilles;
        }

        return $verrouilles;
    }

    /**
     * Is this group closed for the whole semester?
     *
     * Exam grades have no control to hang off, so the control-keyed methods
     * above cannot answer for them. They are keyed by group + semester
     * directly, which is what an exam row already carries through its student.
     */
    public function semestreVerrouille(int $groupeId, int $anneeAcademiqueId, int $semestre): bool
    {
        if ($groupeId <= 0 || $anneeAcademiqueId <= 0) return false;

        return SemestrePublication::where('groupe_id', $groupeId)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->whereIn('type', $this->typesPour($semestre))
            ->where('is_published', true)
            ->exists();
    }

    /**
     * Closed for any of these groups, so a batch can be refused in one lookup.
     *
     * @param  array<int>  $groupeIds
     */
    public function unGroupeVerrouille(array $groupeIds, int $anneeAcademiqueId, int $semestre): bool
    {
        foreach (array_unique($groupeIds) as $groupeId) {
            if ($this->semestreVerrouille((int) $groupeId, $anneeAcademiqueId, $semestre)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this one group closed for this control?
     */
    public function verrouilleGroupe(Controle $controle, $groupeId): bool
    {
        // Fail closed: with no current year the year-scoped lock cannot be
        // resolved, and answering "unlocked" reopened published grades for the
        // whole school. Keep writes blocked until a year is current again.
        if (!$this->hasCurrentAnnee()) return true;

        if ($groupeId === null) return false;

        return in_array((int) $groupeId, $this->groupesVerrouilles($controle), true);
    }

    /**
     * Closed for every group in range. Used when the caller has no group to
     * hand -- publishing everything must still lock everything.
     */
    public function verrouilleTout(Controle $controle): bool
    {
        if (!$this->hasCurrentAnnee()) return true;

        $groupeIds = $this->groupesDuControle($controle);
        if ($groupeIds->isEmpty()) return false;

        $verrouilles = $this->groupesVerrouilles($controle);

        return count($verrouilles) > 0 && count($verrouilles) === $groupeIds->count();
    }

    /**
     * Resolve the effective lock for a request: the chosen group when the caller
     * named one, otherwise the whole range.
     */
    public function verrouillePour(Controle $controle, $groupeId = null): bool
    {
        return $this->verrouilleGroupe($controle, $groupeId)
            || $this->verrouilleTout($controle);
    }
}
