<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Stage units created for the official curricula (coefficient 4) had no
 * sequence and no control, so they could never receive a mark and contributed
 * nothing to any average: MoyenneService only adds a unit's weight once it
 * has at least one note.
 *
 * The Stage page stores its grids as JSON in `stage_evaluations`, a system
 * completely disconnected from `notes`. This migration gives every Stage unit
 * one sequence holding the two marks the official documents count:
 *
 *   controle 1  type 'cc'       -> Grille d'Évaluation  (feeds MPCC)
 *   controle 2  type 'pratique' -> Grille d'Examen      (feeds MPEFCFP)
 *
 * The unit keeps its single coefficient of 4, which is what the documents
 * give it, and StageService copies the two /20 grid results into these
 * controls whenever a grid is saved.
 *
 * Idempotent: a Stage unit that already owns a sequence is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stageUnites = DB::table('unites')
            ->where('nom', 'Stage')
            ->orderBy('id')
            ->get(['id', 'numero_annee', 'semestre', 'ordre']);

        if ($stageUnites->isEmpty()) {
            return;
        }

        $lastOrdre = (int) DB::table('sequences')
            ->whereIn('unite_id', $stageUnites->pluck('id'))
            ->max('ordre');

        foreach ($stageUnites as $unite) {
            $already = DB::table('sequences')->where('unite_id', $unite->id)->exists();
            if ($already) {
                continue;
            }

            $sequenceId = DB::table('sequences')->insertGetId([
                'unite_id'          => $unite->id,
                'nom'               => 'Stage',
                'coefficient'       => 2,
                'nombre_controles'  => 2,
                'ordre'             => ++$lastOrdre,
                'is_active'         => 1,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            DB::table('controles')->insert([
                [
                    'sequence_id' => $sequenceId,
                    'numero'      => 1,
                    'type'        => 'cc',
                    'date'        => null,
                    'note_max'    => 20,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
                [
                    'sequence_id' => $sequenceId,
                    'numero'      => 2,
                    'type'        => 'pratique',
                    'date'        => null,
                    'note_max'    => 20,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ],
            ]);

            DB::table('curriculum_corrections')->insert([
                'cible_type'      => 'unites',
                'cible_id'        => $unite->id,
                'champ'           => 'structure',
                'ancienne_valeur' => '0 sequence / 0 controle',
                'nouvelle_valeur' => '1 sequence / 2 controles (cc + pratique)',
                'source'          => 'grilles officielles du stage : note d\'évaluation et note d\'examen, unite de coefficient 4',
                'applied_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        $stageUnites = DB::table('unites')->where('nom', 'Stage')->pluck('id');

        if ($stageUnites->isEmpty()) {
            return;
        }

        $sequenceIds = DB::table('sequences')
            ->whereIn('unite_id', $stageUnites)
            ->where('nom', 'Stage')
            ->pluck('id');

        if ($sequenceIds->isEmpty()) {
            return;
        }

        $controleIds = DB::table('controles')
            ->whereIn('sequence_id', $sequenceIds)
            ->pluck('id');

        // Rollback is refused as soon as a real grade exists: deleting the
        // controls would silently destroy the marks StageService wrote.
        $noteCount = DB::table('notes')->whereIn('controle_id', $controleIds)->count();
        if ($noteCount > 0) {
            throw new RuntimeException(
                "Rollback annulé : $noteCount note(s) existent sur les contrôles Stage. "
                . 'Désactivez les unités Stage (is_active = 0) au lieu de supprimer la structure.'
            );
        }

        DB::transaction(function () use ($sequenceIds, $controleIds, $stageUnites) {
            // Safe to remove: the guard above proved no note depends on them.
            if ($controleIds->isNotEmpty()) {
                DB::table('controles')->whereIn('id', $controleIds)->delete();
            }

            DB::table('sequences')->whereIn('id', $sequenceIds)->delete();

            DB::table('curriculum_corrections')
                ->where('cible_type', 'unites')
                ->where('champ', 'structure')
                ->whereIn('cible_id', $stageUnites)
                ->delete();
        });
    }
};
