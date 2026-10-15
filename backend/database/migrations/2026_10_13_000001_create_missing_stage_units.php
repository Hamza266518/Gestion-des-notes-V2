<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Some filière/année pairs document their stage services in
 * database/data/stage_services.json but had no "Stage" unite at all — Infirmier
 * Auxiliaire 1ère année was the only one left. Without the unit the services
 * dropdown of the Stage grids stays empty and there is nowhere to store the
 * marks, so create the unit (one contrôle per service) wherever the JSON
 * defines services but no active unit exists yet.
 *
 * Mirrors the shape of the existing Stage units: coefficient 4, semestre 2,
 * sequence-free direct controls.
 *
 * Idempotent: a filière/année that already owns an active "Stage" unite is left
 * untouched, so re-running never duplicates a unit or a service.
 */
return new class extends Migration
{
    public function up(): void
    {
        $services = json_decode((string) file_get_contents(database_path('data/stage_services.json')), true) ?: [];

        foreach ($services as $key => $names) {
            if (! is_array($names) || $names === []) {
                continue;
            }

            [$code, $numeroAnnee] = array_pad(explode('|', $key, 2), 2, null);
            if ($code === null || $numeroAnnee === null) {
                continue;
            }

            $filiere = DB::table('filieres')->where('code', $code)->first();
            if (! $filiere) {
                continue;
            }

            $exists = DB::table('unites')
                ->where('nom', 'Stage')
                ->where('filiere_id', $filiere->id)
                ->where('numero_annee', (int) $numeroAnnee)
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                continue;
            }

            $ordre = (int) DB::table('unites')
                ->where('filiere_id', $filiere->id)
                ->where('numero_annee', (int) $numeroAnnee)
                ->max('ordre') + 1;

            $uniteId = DB::table('unites')->insertGetId([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Stage',
                'coefficient'  => 4,
                'numero_annee' => (int) $numeroAnnee,
                'semestre'     => 2,
                'ordre'        => $ordre,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            foreach ($names as $index => $name) {
                DB::table('controles')->insert([
                    'sequence_id' => null,
                    'unite_id'    => $uniteId,
                    'numero'      => $index + 1,
                    'nom'         => $name,
                    'date'        => null,
                    'note_max'    => 20,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            DB::table('curriculum_corrections')->insert([
                'cible_type'      => 'unites',
                'cible_id'        => $uniteId,
                'champ'           => 'structure',
                'ancienne_valeur' => 'aucune unité Stage',
                'nouvelle_valeur' => count($names) . " services de stage ({$code}{$numeroAnnee})",
                'source'          => 'Programme Des Stages.docx : Lieux des stages',
                'applied_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deleting the unit would destroy the marks its services may already
        // hold. Deactivate it (is_active = 0) instead of dropping the rows.
    }
};
