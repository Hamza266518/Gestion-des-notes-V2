<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every filière/année has its own number of Stage controls. The official
 * documents count them as plain "Contrôle" grades belonging to the Stage unit
 * itself — not to a sequence:
 *
 *   Aide-Soignant        (AS)   : 10 contrôles (1ère année)
 *   Infirmier Auxiliaire (IA1/2):  7 puis 12
 *   Infirmier Polyvalent (IP1/3):  4, 7 puis 14
 *
 * This migration makes `controles.sequence_id` nullable and adds a nullable
 * `controles.unite_id`, then rehomes every Stage unit's controls onto the unit
 * (sequence_id NULL, unite_id = Stage unite, numbered 1..N, named "Contrôle N").
 * StageService still writes the two grid marks into Contrôle 1 and Contrôle 2;
 * the remaining controls stay empty and count as 0 in the averages, exactly like
 * a control without an entered note on a normal unit.
 *
 * Existing marks are preserved: the controls 1..2 keep their rows (and thus
 * their notes), they are only detached from the old sequence before it is
 * deleted.
 *
 * Idempotent/re-runnable: a Stage unit that already owns sequence-free direct
 * controls is completed up to its count, and any leftover "Stage" sequence is
 * removed.
 */
return new class extends Migration
{
    /** Filière name -> (numero_annee => number of Stage controls). */
    private const CONTROLES_PAR_FILIERE = [
        'Aide-Soignant'        => [1 => 10],
        'Infirmier Auxiliaire' => [1 => 7, 2 => 12],
        'Infirmier Polyvalent' => [1 => 4, 2 => 7, 3 => 14],
    ];

    /** Fallback for a filière the map does not know (e.g. the SCANEX fixture). */
    private const CONTROLES_PAR_DEFAUT = 2;

    public function up(): void
    {
        $sequenceFk = $this->foreignKeyExists('controles', 'sequence_id');

        if ($sequenceFk) {
            Schema::table('controles', function (Blueprint $table) {
                $table->dropForeign(['sequence_id']);
            });
        }

        Schema::table('controles', function (Blueprint $table) {
            $table->unsignedBigInteger('sequence_id')->nullable()->change();
        });

        if (! Schema::hasColumn('controles', 'unite_id')) {
            Schema::table('controles', function (Blueprint $table) {
                $table->unsignedBigInteger('unite_id')->nullable()->after('sequence_id');
                $table->foreign('unite_id')->references('id')->on('unites')->cascadeOnDelete();
            });
        } elseif (! $this->foreignKeyExists('controles', 'unite_id')) {
            Schema::table('controles', function (Blueprint $table) {
                $table->foreign('unite_id')->references('id')->on('unites')->cascadeOnDelete();
            });
        }

        if ($sequenceFk) {
            Schema::table('controles', function (Blueprint $table) {
                $table->foreign('sequence_id')->references('id')->on('sequences')->cascadeOnDelete();
            });
        }

        $stageUnites = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->where('unites.nom', 'Stage')
            ->get(['unites.id', 'unites.numero_annee', 'filieres.nom as filiere_nom']);

        foreach ($stageUnites as $unite) {
            $target = self::CONTROLES_PAR_FILIERE[$unite->filiere_nom][(int) $unite->numero_annee]
                ?? self::CONTROLES_PAR_DEFAUT;

            $this->detacheSequences((int) $unite->id);
            $this->assureControlesDirects((int) $unite->id, $target, $unite->filiere_nom);
        }
    }

    public function down(): void
    {
        $stageUnites = DB::table('unites')->where('nom', 'Stage')->get(['id']);

        // Rollback would delete sequence-free Stage controls (and later one-shot
        // removal would destroy their marks), so a single real grade forbids it —
        // the same protection the previous Stage structure migration used.
        $controlIds = $stageUnites->isNotEmpty()
            ? DB::table('controles')->whereIn('unite_id', $stageUnites->pluck('id'))->pluck('id')
            : collect();
        $noteCount = DB::table('notes')->whereIn('controle_id', $controlIds)->count();
        if ($noteCount > 0) {
            throw new RuntimeException(
                "Rollback annulé : $noteCount note(s) existent sur les contrôles Stage. "
                . 'Désactivez les unités Stage (is_active = 0) au lieu de supprimer la structure.'
            );
        }

        foreach ($stageUnites as $unite) {
            if (DB::table('sequences')->where('unite_id', $unite->id)->exists()) {
                continue;
            }
            $this->restaureSequence((int) $unite->id);
        }

        // The schema itself is deliberately NOT reverted. Sequence-free direct
        // controls are shared with other units now — the Soutenance unit stores
        // the jury criteria the same way — so forcing `controles.sequence_id`
        // back to NOT NULL, or dropping `controles.unite_id`, would orphan every
        // non-Stage direct control. Only the Stage structure is rolled back here.
    }

    /** True when the column is referenced by a foreign key on the same table. */
    private function foreignKeyExists(string $table, string $column): bool
    {
        $rows = DB::select(
            'select 1 from information_schema.KEY_COLUMN_USAGE
             where TABLE_SCHEMA = ? and TABLE_NAME = ? and COLUMN_NAME = ? and REFERENCED_TABLE_NAME is not null
             limit 1',
            [DB::getDatabaseName(), $table, $column]
        );

        return count($rows) > 0;
    }

    /**
     * Move every control of the unit's sequences onto the unit, then drop those
     * sequences (their pivot rows cascade). This makes the unit self-contained.
     */
    private function detacheSequences(int $uniteId): void
    {
        $seqIds = DB::table('sequences')->where('unite_id', $uniteId)->pluck('id');
        if ($seqIds->isEmpty()) {
            return;
        }

        $controles = DB::table('controles')
            ->whereIn('sequence_id', $seqIds)
            ->orderBy('numero')
            ->orderBy('id')
            ->get();

        foreach ($controles as $controle) {
            DB::table('controles')->where('id', $controle->id)->update([
                'sequence_id' => null,
                'unite_id'    => $uniteId,
                'nom'         => $controle->nom ?: ('Contrôle ' . $controle->numero),
            ]);
        }

        DB::table('sequences')->whereIn('id', $seqIds)->delete();
    }

    /**
     * Renumber the unit's direct controls 1..M (marks follow the rows, so the
     * numbering only affects display order), then create the missing controls up
     * to the official count and log the structure change once.
     */
    private function assureControlesDirects(int $uniteId, int $target, string $filiereNom): void
    {
        $direct = DB::table('controles')
            ->where('unite_id', $uniteId)
            ->orderBy('numero')
            ->orderBy('id')
            ->get();

        foreach ($direct as $index => $controle) {
            $numero = $index + 1;
            DB::table('controles')->where('id', $controle->id)->update([
                'numero' => $numero,
                'nom'    => 'Contrôle ' . $numero,
            ]);
        }

        $count = $direct->count();
        for ($numero = $count + 1; $numero <= $target; $numero++) {
            DB::table('controles')->insert([
                'sequence_id' => null,
                'unite_id'    => $uniteId,
                'numero'      => $numero,
                'nom'         => 'Contrôle ' . $numero,
                'date'        => null,
                'note_max'    => 20,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        DB::table('curriculum_corrections')->updateOrInsert(
            [
                'cible_type'      => 'unites',
                'cible_id'        => $uniteId,
                'champ'           => 'structure',
            ],
            [
                'ancienne_valeur' => '1 sequence / 2 controles',
                'nouvelle_valeur' => "0 sequence / {$target} controles ({$filiereNom})",
                'source'          => 'grilles officielles : chaque filière/année possède ses propres contrôles de stage, sans séquence',
                'applied_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]
        );
    }

    /**
     * Put the unit back to the structure migration 2026_09_29_000007 created:
     * one "Stage" sequence holding controls 1 and 2 (the two grid marks).
     */
    private function restaureSequence(int $uniteId): void
    {
        $direct = DB::table('controles')
            ->where('unite_id', $uniteId)
            ->orderBy('numero')
            ->orderBy('id')
            ->get();

        $lastOrdre = (int) DB::table('sequences')->where('unite_id', $uniteId)->max('ordre');
        $sequenceId = DB::table('sequences')->insertGetId([
            'unite_id'          => $uniteId,
            'nom'               => 'Stage',
            'coefficient'       => 2,
            'nombre_controles'  => 2,
            'ordre'             => $lastOrdre + 1,
            'is_active'         => 1,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        foreach ($direct as $index => $controle) {
            if ($index < 2) {
                DB::table('controles')->where('id', $controle->id)->update([
                    'sequence_id' => $sequenceId,
                    'unite_id'    => null,
                    'numero'      => $index + 1,
                    'nom'         => 'Contrôle ' . ($index + 1),
                ]);
                continue;
            }
            DB::table('controles')->where('id', $controle->id)->delete();
        }

        for ($numero = $direct->count() + 1; $numero <= 2; $numero++) {
            DB::table('controles')->insert([
                'sequence_id' => $sequenceId,
                'unite_id'    => null,
                'numero'      => $numero,
                'nom'         => 'Contrôle ' . $numero,
                'date'        => null,
                'note_max'    => 20,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
};