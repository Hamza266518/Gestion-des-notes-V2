<?php

namespace App\Services;

use App\Models\Etudiant;
use App\Models\ScanNameCache;

/**
 * Resolves a name read off a scanned sheet to a student, strictly inside the
 * perimeter (set of groupe ids + academic year) the sheet was scanned for.
 *
 * Used by the formateur control scanner and the admin exam scanner so both
 * read their sheets the same way: by name against a list, never by anonymous
 * codes.
 */
class ScanEtudiantMatcher
{
    /**
     * Strategies, from strictest to loosest:
     *   0. the registration number printed on the sheet, when Gemini read it
     *   1. the remembered scan-name cache
     *   2. full name as a substring
     *   3. every word present (handles a reversed name)
     *   4. first word + last word (handles middle-name variations)
     *
     * Every strategy is restricted to $groupeIds (+ $anneeId). When more than
     * one student matches, the row stays "introuvable" rather than guessing: a
     * wrong guess would write the grade onto the wrong person.
     *
     * @param iterable<int> $groupeIds
     */
    public function match(
        $groupeIds,
        ?int $anneeId,
        string $name,
        ?string $numeroScanne = null,
        ?int $cacheUserId = null
    ): ?Etudiant {
        $searchName = trim($name);
        $numeroScanne = $numeroScanne !== null ? trim($numeroScanne) : '';

        if (!is_countable($groupeIds) || count($groupeIds) === 0) {
            return null;
        }
        // A row is only worth matching when we have at least one identifier.
        if ($searchName === '' && $numeroScanne === '') {
            return null;
        }

        $inPerimetre = fn ($q) => $q->whereIn('groupe_id', $groupeIds)
            ->when($anneeId, fn ($q2) => $q2->where('annee_academique_id', $anneeId));

        // 0. the registration number printed on the sheet. Strictest check, so
        // it runs first; still bounded by the perimeter.
        if ($numeroScanne !== '') {
            $parNumero = $inPerimetre(Etudiant::with('groupe'))
                ->whereRaw('REPLACE(numero_inscription, " ", "") = ?', [
                    str_replace(' ', '', $numeroScanne),
                ])
                ->get();

            if ($parNumero->count() === 1) {
                return $parNumero->first();
            }
            // Several students sharing the number, or none: fall through to the
            // name rather than guessing on an unreliable read.
        }

        if ($searchName === '') {
            return null;
        }

        // 1. remembered mapping from an earlier scan, for this user only.
        // A null user id (only reachable from callers with no cache owner) is
        // treated as "no one owns this row" rather than as "everyone", so the
        // cache can never widen across users.
        $normalized = ScanNameCache::normalizeName($name);
        if ($normalized !== '') {
            $cache = ScanNameCache::where('raw_nom_normalized', $normalized)
                ->when(
                    $cacheUserId,
                    fn ($q) => $q->where('formateur_id', $cacheUserId),
                    fn ($q) => $q->whereNull('formateur_id')
                )
                ->first();
            if ($cache) {
                $cached = $inPerimetre(Etudiant::with('groupe'))->find($cache->etudiant_id);
                if ($cached) {
                    return $cached;
                }
            }
        }

        $safeName = str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($searchName));
        $safeName = preg_replace('/\s+/', ' ', $safeName) ?: $safeName;
        $words = array_values(array_filter(explode(' ', $safeName), fn ($w) => mb_strlen($w) > 1));

        // 2. full name as a substring
        $exact = $inPerimetre(Etudiant::with('groupe'))
            ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $safeName . '%'])
            ->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            return null; // ambiguous: better "introuvable" than a wrong student
        }

        // 3. all words present, in any order
        if (count($words) >= 2) {
            $q = $inPerimetre(Etudiant::with('groupe'));
            foreach ($words as $w) {
                $q->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $w . '%']);
            }
            $all = $q->get();
            if ($all->count() === 1) {
                return $all->first();
            }
        }

        // 4. first word + last word only
        if (count($words) >= 2) {
            $first = $words[0];
            $last = $words[count($words) - 1];
            $partial = $inPerimetre(Etudiant::with('groupe'))
                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $first . '%'])
                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $last . '%'])
                ->get();
            if ($partial->count() === 1) {
                return $partial->first();
            }
        }

        return null;
    }
}