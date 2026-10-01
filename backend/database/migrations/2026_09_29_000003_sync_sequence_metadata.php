<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE = 'base de donnees (comptage reel des controles)';

    public function up(): void
    {
        $real = DB::table('controles')
            ->select('sequence_id', DB::raw('COUNT(*) as c'))
            ->groupBy('sequence_id')
            ->pluck('c', 'sequence_id');

        $seqs = DB::table('sequences')->orderBy('unite_id')->orderBy('ordre')->orderBy('id')->get();

        DB::transaction(function () use ($seqs, $real) {
            foreach ($seqs as $s) {
                $actual = (int) ($real[$s->id] ?? 0);
                if ((int) ($s->nombre_controles ?? 0) !== $actual) {
                    DB::table('sequences')->where('id', $s->id)->update(['nombre_controles' => $actual]);
                    $this->log('sequences', $s->id, 'nombre_controles', (string) $s->nombre_controles, (string) $actual);
                }
            }

            $this->fixSequenceOrdreTies();
            $this->fixUniteOrdreTies();
        });
    }

    /**
     * Only rows that share a duplicated `ordre` inside the same parent are
     * touched. The first row of a tie keeps its value; the others are pushed to
     * the next free integer so ordering stays stable and minimal.
     */
    private function fixSequenceOrdreTies(): void
    {
        foreach (DB::table('sequences')->orderBy('unite_id')->get()->groupBy('unite_id') as $rows) {
            // reserve every ordre already in use so a relocated row never
            // lands on a value owned by a row we have not processed yet
            $used = [];
            foreach ($rows as $r) {
                $used[(int) $r->ordre] = true;
            }

            $byOrdre = [];
            foreach ($rows as $r) {
                $byOrdre[(int) $r->ordre][] = $r;
            }
            ksort($byOrdre);

            foreach ($byOrdre as $ordre => $group) {
                if (count($group) < 2) {
                    continue;
                }
                $first = true;
                foreach ($group as $r) {
                    if ($first) {
                        $first = false;
                        continue;
                    }
                    $next = $ordre;
                    while (isset($used[$next])) {
                        $next++;
                    }
                    $used[$next] = true;
                    DB::table('sequences')->where('id', $r->id)->update(['ordre' => $next]);
                    $this->log('sequences', $r->id, 'ordre', (string) $ordre, (string) $next);
                }
            }
        }
    }

    private function fixUniteOrdreTies(): void
    {
        $unites = DB::table('unites')->orderBy('id')->get();
        $scope = fn($u) => $u->filiere_id . ':' . $u->numero_annee . ':' . $u->semestre;

        $groups = [];
        foreach ($unites as $u) {
            $groups[$scope($u)][(int) $u->ordre][] = $u;
        }

        foreach ($groups as $key => $byOrdre) {
            ksort($byOrdre);
            $used = [];
            foreach ($byOrdre as $ordre => $group) {
                $used[$ordre] = true;
            }
            foreach ($byOrdre as $ordre => $group) {
                if (count($group) < 2) {
                    continue;
                }
                $first = true;
                foreach ($group as $u) {
                    if ($first) {
                        $first = false;
                        continue;
                    }
                    $next = $ordre;
                    while (isset($used[$next])) {
                        $next++;
                    }
                    $used[$next] = true;
                    DB::table('unites')->where('id', $u->id)->update(['ordre' => $next]);
                    $this->log('unites', $u->id, 'ordre', (string) $ordre, (string) $next);
                }
            }
        }
    }

    private function log(string $type, int $id, string $champ, string $old, string $new): void
    {
        DB::table('curriculum_corrections')->insert([
            'cible_type' => $type,
            'cible_id' => $id,
            'champ' => $champ,
            'ancienne_valeur' => $old,
            'nouvelle_valeur' => $new,
            'source' => self::SOURCE,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $rows = DB::table('curriculum_corrections')
            ->where('cible_type', 'sequences')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                $col = in_array($r->champ, ['nombre_controles', 'ordre'], true) ? $r->champ : null;
                if ($col === null) continue;
                $value = $r->ancienne_valeur === null ? null : (int) $r->ancienne_valeur;
                DB::table('sequences')->where('id', $r->cible_id)->update([$col => $value]);
            }
            DB::table('curriculum_corrections')->where('cible_type', 'sequences')->delete();
        });

        $unitRows = DB::table('curriculum_corrections')
            ->where('cible_type', 'unites')
            ->where('champ', 'ordre')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($unitRows) {
            foreach ($unitRows as $r) {
                DB::table('unites')->where('id', $r->cible_id)->update([
                    'ordre' => (int) $r->ancienne_valeur,
                ]);
            }
            DB::table('curriculum_corrections')
                ->where('cible_type', 'unites')
                ->where('champ', 'ordre')
                ->delete();
        });
    }
};
