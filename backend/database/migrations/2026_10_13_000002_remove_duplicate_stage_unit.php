<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * IA 1ère année carried two "stage" units: the canonical sequence-free "Stage"
 * unit (one contrôle per service) created by
 * 2026_10_13_000001_create_missing_stage_units, and a legacy "Notion de
 * méthodologie de recherche (Stage)" unit that still held a "Stage" sequence
 * with two empty controls. Only one may exist, or the service dropdown and the
 * averages disagree.
 *
 * This removes the legacy duplicate wherever a filière/année already owns an
 * active, sequence-free "Stage" unit. It only touches units carrying a legacy
 * "Stage" sequence and never deletes one that holds a single note: existing
 * grades always win over the cleanup.
 *
 * Idempotent: once the duplicate is gone, there is nothing left to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        $canonical = DB::table('unites')
            ->where('nom', 'Stage')
            ->where('is_active', true)
            ->get(['id', 'filiere_id', 'numero_annee']);

        foreach ($canonical as $unite) {
            $others = DB::table('unites')
                ->where('filiere_id', $unite->filiere_id)
                ->where('numero_annee', $unite->numero_annee)
                ->where('id', '!=', $unite->id)
                ->where('is_active', true)
                ->get(['id', 'nom']);

            foreach ($others as $dup) {
                $sequenceIds = DB::table('sequences')->where('unite_id', $dup->id)->pluck('id');

                // Only the legacy shape, marked by a "Stage" sequence.
                $isLegacy = DB::table('sequences')
                    ->whereIn('id', $sequenceIds)
                    ->where('nom', 'Stage')
                    ->exists();

                if (! $isLegacy) {
                    continue;
                }

                $controleIds = DB::table('controles')
                    ->where(function ($q) use ($dup, $sequenceIds) {
                        $q->where('unite_id', $dup->id)
                          ->orWhereIn('sequence_id', $sequenceIds);
                    })
                    ->pluck('id');

                $hasNotes = $controleIds->isNotEmpty()
                    && DB::table('notes')->whereIn('controle_id', $controleIds)->exists();

                if ($hasNotes) {
                    continue;
                }

                DB::table('controles')->whereIn('id', $controleIds)->delete();
                DB::table('sequences')->whereIn('id', $sequenceIds)->delete();
                DB::table('unites')->where('id', $dup->id)->delete();

                DB::table('curriculum_corrections')->insert([
                    'cible_type'      => 'unites',
                    'cible_id'        => $dup->id,
                    'champ'           => 'structure',
                    'ancienne_valeur' => $dup->nom,
                    'nouvelle_valeur' => 'unité supprimée (doublon du Stage)',
                    'source'          => 'une seule unité Stage par filière/année',
                    'applied_at'      => now(),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // The duplicate was an empty legacy shell; nothing to restore.
    }
};
