<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy sequences created their three controles all with numero = 1
 * (they were only distinguished by type: cc / theorique / pratique).
 * Every label, sort and de-duplication keyed on numero, so those grids
 * rendered "Contrôle 1" three times and collapsed the bulletin columns.
 *
 * Each sequence numbers its own controles 1..N (cc first, then
 * theorique, then pratique — matching HasControleType for a count of 3).
 * Sequences that already have unique numbers are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicated = DB::select(
            'SELECT sequence_id FROM controles
             GROUP BY sequence_id
             HAVING COUNT(*) > COUNT(DISTINCT numero)'
        );

        foreach ($duplicated as $row) {
            $controles = DB::table('controles')
                ->where('sequence_id', $row->sequence_id)
                ->orderByRaw("CASE type WHEN 'cc' THEN 0 WHEN 'theorique' THEN 1 WHEN 'pratique' THEN 2 ELSE 3 END")
                ->orderBy('id')
                ->get();

            foreach ($controles as $index => $controle) {
                DB::table('controles')
                    ->where('id', $controle->id)
                    ->update(['numero' => $index + 1]);
            }
        }
    }

    public function down(): void
    {
        // One-way data repair: the old duplicated numbers cannot be restored.
    }
};
