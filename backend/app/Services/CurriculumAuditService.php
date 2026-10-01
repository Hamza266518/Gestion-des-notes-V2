<?php

namespace App\Services;

use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Support\Facades\DB;

/**
 * Compares the database against the official curriculum documents.
 *
 * This service is strictly read-only. It never writes. Applying the result is a
 * separate, explicit, reviewed migration step.
 */
class CurriculumAuditService
{
    /**
     * @return array{
     *   summary: array<string,int>,
     *   unites: array<int, array<string,mixed>>,
     *   unresolved: array<string,string>,
     *   conflicts: array<string,string>,
     *   unmatched_official: array<int, array<string,mixed>>
     * }
     */
    public function audit(?string $filiereCode = null): array
    {
        $unites = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->orderBy('filieres.code')
            ->orderBy('unites.numero_annee')
            ->orderBy('unites.id')
            ->get([
                'unites.id', 'unites.nom', 'unites.coefficient', 'unites.semestre',
                'unites.numero_annee', 'unites.ordre', 'unites.is_active',
                'filieres.code as filiere_code', 'filieres.nom as filiere_nom',
            ]);

        $realControls = DB::table('controles')
            ->join('sequences', 'sequences.id', '=', 'controles.sequence_id')
            ->select('sequences.unite_id', DB::raw('COUNT(*) as c'))
            ->groupBy('sequences.unite_id')
            ->pluck('c', 'sequences.unite_id');

        $noteCounts = DB::table('notes')
            ->join('controles', 'controles.id', '=', 'notes.controle_id')
            ->join('sequences', 'sequences.id', '=', 'controles.sequence_id')
            ->select('sequences.unite_id', DB::raw('COUNT(*) as c'))
            ->groupBy('sequences.unite_id')
            ->pluck('c', 'sequences.unite_id');

        $official = OfficialCurriculum::coefficients();
        $unresolved = OfficialCurriculum::unresolved();
        $conflicts = OfficialCurriculum::conflicts();

        $rows = [];
        $matchedOfficial = [];

        foreach ($unites as $u) {
            $code = $u->filiere_code;
            $annee = (int) $u->numero_annee;

            if ($filiereCode !== null && strcasecmp($code, $filiereCode) !== 0) {
                continue;
            }

            $key = "{$code}:{$annee}";
            $isUnresolved = isset($unresolved[$key]);

            $yearOfficial = $official[$code][$annee] ?? null;
            $match = null;

            if (!$isUnresolved && is_array($yearOfficial)) {
                $alias = OfficialCurriculum::aliases()[$code][$annee][$u->nom] ?? null;
                $target = $alias !== null
                    ? OfficialCurriculum::normalise($alias[0])
                    : OfficialCurriculum::normalise($u->nom);

                foreach ($yearOfficial as $idx => $o) {
                    if (!isset($matchedOfficial["{$code}|{$annee}|{$idx}"]) && OfficialCurriculum::normalise($o['nom']) === $target) {
                        $match = $o;
                        $matchedOfficial["{$code}|{$annee}|{$idx}"] = true;
                        break;
                    }
                }
            }

            $expected = $match['coefficient'] ?? null;
            $current = (int) $u->coefficient;

            $status = match (true) {
                $isUnresolved => 'unresolved',
                $match === null => 'no_source',
                $current === $expected => 'ok',
                default => 'change',
            };

            $rows[] = [
                'id' => $u->id,
                'filiere' => $code,
                'annee' => $annee,
                'semestre' => $u->semestre,
                'ordre' => $u->ordre,
                'nom' => $u->nom,
                'current' => $current,
                'official' => $expected,
                'official_nom' => $match['nom'] ?? null,
                'status' => $status,
                'is_active' => (bool) $u->is_active,
                'real_controles' => (int) ($realControls[$u->id] ?? 0),
                'notes' => (int) ($noteCounts[$u->id] ?? 0),
            ];
        }

        $unmatched = [];
        foreach ($official as $code => $years) {
            if ($filiereCode !== null && strcasecmp($code, $filiereCode) !== 0) {
                continue;
            }
            foreach ($years as $annee => $list) {
                if ($list === null) {
                    continue;
                }
                foreach ($list as $idx => $o) {
                    if (!isset($matchedOfficial["{$code}|{$annee}|{$idx}"])) {
                        $unmatched[] = [
                            'filiere' => $code,
                            'annee' => $annee,
                            'nom' => $o['nom'],
                            'coefficient' => $o['coefficient'],
                        ];
                    }
                }
            }
        }

        $summary = array_merge(
            array_fill_keys(array_values(array_unique(array_column($rows, 'status'))), 0),
            ['change' => 0, 'ok' => 0, 'unresolved' => 0, 'no_source' => 0]
        );
        foreach ($rows as $r) {
            $summary[$r['status']] = ($summary[$r['status']] ?? 0) + 1;
        }

        return [
            'summary' => $summary,
            'unites' => $rows,
            'unresolved' => $unresolved,
            'conflicts' => $conflicts,
            'unmatched_official' => $unmatched,
        ];
    }

    /**
     * Report of declared vs real control counts, and sequence coefficient sanity.
     *
     * @return array{summary:array<string,int>, sequences:array<int,array<string,mixed>>}
     */
    public function sequenceAudit(): array
    {
        $seqs = DB::table('sequences')
            ->orderBy('unite_id')
            ->orderBy('ordre')
            ->get(['id', 'unite_id', 'nom', 'coefficient', 'nombre_controles', 'ordre', 'is_active']);

        $real = DB::table('controles')
            ->select('sequence_id', DB::raw('COUNT(*) as c'))
            ->groupBy('sequence_id')
            ->pluck('c', 'sequence_id');

        $rows = [];
        $summary = ['nbc_mismatch' => 0, 'coef_equals_nbc' => 0, 'coef_zero' => 0, 'ordre_tie' => 0];

        $tieIndex = [];
        foreach ($seqs as $s) {
            $tieIndex[$s->unite_id . '|' . $s->ordre][] = $s->id;
        }

        foreach ($seqs as $s) {
            $realCount = (int) ($real[$s->id] ?? 0);
            $declared = (int) ($s->nombre_controles ?? 0);
            $coef = (int) $s->coefficient;
            $isTie = count($tieIndex[$s->unite_id . '|' . $s->ordre] ?? []) > 1;

            if ($declared !== $realCount) $summary['nbc_mismatch']++;
            if ($coef === $declared) $summary['coef_equals_nbc']++;
            if ($coef === 0) $summary['coef_zero']++;
            if ($isTie) $summary['ordre_tie']++;

            $rows[] = [
                'id' => $s->id,
                'unite_id' => $s->unite_id,
                'nom' => $s->nom,
                'ordre' => $s->ordre,
                'coefficient' => $coef,
                'declared' => $declared,
                'real' => $realCount,
                'ordre_tie' => $isTie,
            ];
        }

        return ['summary' => $summary, 'sequences' => $rows];
    }
}
