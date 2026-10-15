<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôles are plain "Contrôle continu" marks, but the data model carried a
 * vestigial `type` (cc / theorique / pratique) that HasControleType assigned to
 * every sequence of 3+ controls. The live data was mass-created with 3 controls
 * per sequence, so 150 sequences ended up with a bogus cc + theorique + pratique
 * trio and the count no longer matched the programme.
 *
 * The real number of contrôles per sequence comes from the source documents
 * (`Suivi Controle.docx` and, for IP 3ème, `2 Les notes des controles ...`), and
 * is materialised in database/data/controle_counts.json. This migration makes
 * each sequence exactly that many plain contrôles, then drops the column.
 *
 * Surplus controls are deleted even when they hold notes (the grades cascade),
 * which the school explicitly chose: the documented count wins.
 *
 * Down() restores the column shape, but the deleted controls and their grades
 * cannot be recovered -- this is a one-way data repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        $map = json_decode((string) file_get_contents(database_path('data/controle_counts.json')), true) ?: [];

        $sequences = DB::table('sequences as s')
            ->join('unites as u', 'u.id', '=', 's.unite_id')
            ->join('filieres as f', 'f.id', '=', 'u.filiere_id')
            ->select('s.id', 's.nom as sname', 'u.nom as uname', 'u.numero_annee as an', 'f.code as code')
            ->get();

        foreach ($sequences as $sequence) {
            $key = $sequence->code . '|' . $sequence->an . '|' . $sequence->uname . '|' . $sequence->sname;

            $target = array_key_exists($key, $map) ? (int) $map[$key] : null;
            if ($target === null && strtolower($sequence->uname) === 'stage') {
                $target = 2; // Évaluation de stage + Examen de stage
            }
            if ($target === null) {
                continue; // No documented count: leave the sequence untouched.
            }

            $existing = DB::table('controles')
                ->where('sequence_id', $sequence->id)
                ->get()
                ->keyBy('numero');

            $surplus = $existing->keys()->map(fn ($n) => (int) $n)->filter(fn ($n) => $n > $target);
            if ($surplus->isNotEmpty()) {
                DB::table('controles')
                    ->where('sequence_id', $sequence->id)
                    ->whereIn('numero', $surplus->all())
                    ->delete();
            }

            for ($n = 1; $n <= $target; $n++) {
                if ($existing->has($n)) {
                    DB::table('controles')
                        ->where('sequence_id', $sequence->id)
                        ->where('numero', $n)
                        ->update(['nom' => 'Contrôle ' . $n, 'note_max' => 20]);
                } else {
                    DB::table('controles')->insert([
                        'sequence_id' => $sequence->id,
                        'numero'      => $n,
                        'nom'         => 'Contrôle ' . $n,
                        'note_max'    => 20,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]);
                }
            }

            DB::table('sequences')->where('id', $sequence->id)->update(['nombre_controles' => $target]);
        }

        Schema::table('controles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->enum('type', ['cc', 'theorique', 'pratique'])->default('cc')->after('numero');
        });
    }
};
