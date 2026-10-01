<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `sequences.coefficient` is not a curriculum weight: no average in the app
 * ever multiplies by it. It only mirrors how many controls the sequence has.
 *
 * Thirteen IA2 sequences still carried the coefficient 0 they had back when
 * they declared no control at all, even though each of them actually has 3
 * controls. Migration 000003 corrected `nombre_controles` but deliberately
 * left `coefficient` alone, so the stale 0 kept showing up as a "Coeff 0"
 * badge and read like a data error.
 *
 * This brings the value back in line with the control count. Purely cosmetic:
 * no note, average or bulletin changes.
 */
return new class extends Migration
{
    private const SOURCE = 'comptage reel des controles (valeur derivee, non utilisee comme ponderation)';

    public function up(): void
    {
        $affected = DB::table('sequences')
            ->where('coefficient', '<=', 0)
            ->where('nombre_controles', '>', 0)
            ->orderBy('id')
            ->get(['id', 'coefficient', 'nombre_controles']);

        if ($affected->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($affected) {
            foreach ($affected as $seq) {
                DB::table('sequences')->where('id', $seq->id)->update([
                    'coefficient' => (int) $seq->nombre_controles,
                ]);

                DB::table('curriculum_corrections')->insert([
                    'cible_type'      => 'sequences',
                    'cible_id'        => $seq->id,
                    'champ'           => 'coefficient',
                    'ancienne_valeur' => (string) $seq->coefficient,
                    'nouvelle_valeur' => (string) $seq->nombre_controles,
                    'source'          => self::SOURCE,
                    'applied_at'      => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $rows = DB::table('curriculum_corrections')
            ->where('cible_type', 'sequences')
            ->where('champ', 'coefficient')
            ->where('source', self::SOURCE)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                DB::table('sequences')->where('id', $row->cible_id)->update([
                    'coefficient' => (int) $row->ancienne_valeur,
                ]);
            }

            DB::table('curriculum_corrections')
                ->where('cible_type', 'sequences')
                ->where('champ', 'coefficient')
                ->where('source', self::SOURCE)
                ->delete();
        });
    }
};
