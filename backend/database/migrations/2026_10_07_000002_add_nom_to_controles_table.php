<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `controles.nom` was referenced by SequenceController::store (creation of a
 * sequence inserts "Contrôle 1..N"), by renameControle and by the Unites page
 * chips — but the column never existed, so creating a sequence returned a 500
 * and renames were silent no-ops. Add it and backfill every existing row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->string('nom')->nullable()->after('numero');
        });

        $rows = DB::table('controles')->whereNull('nom')->get(['id', 'numero']);
        foreach ($rows as $row) {
            DB::table('controles')->where('id', $row->id)->update(['nom' => 'Contrôle ' . $row->numero]);
        }
    }

    public function down(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->dropColumn('nom');
        });
    }
};
