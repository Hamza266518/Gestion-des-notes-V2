<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Each Stage unit owns one contrôle per service the student rotates through.
 * Migration 2026_10_10_000001 created them numbered ("Contrôle 1" ... "Contrôle N");
 * the Stage grids now show that list of services as a dropdown, so the controls
 * must carry the real names.
 *
 * This renames every Stage unit's controls after the "Lieux des stages" section
 * of Programmes Des Stages.docx, kept in database/data/stage_services.json as
 * {filieres.code|filieres.numero_annee: [names]}.
 *
 * Rows keep their id, and therefore their notes: a contrôle already holding a
 * mark is renamed, never recreated. Only the trailing controls beyond the
 * documented list are removed, and only while they are empty — a grade always
 * forbids the deletion.
 *
 * Down() cannot restore the former "Contrôle N" labels.
 */
return new class extends Migration
{
    public function up(): void
    {
        $services = json_decode((string) file_get_contents(database_path('data/stage_services.json')), true) ?: [];

        $unites = DB::table('unites as u')
            ->join('filieres as f', 'f.id', '=', 'u.filiere_id')
            ->where('u.nom', 'Stage')
            ->where('u.is_active', true)
            ->orderBy('u.id')
            ->get(['u.id', 'u.numero_annee as an', 'f.code as code']);

        foreach ($unites as $unite) {
            $names = $services[$unite->code . '|' . $unite->an] ?? null;
            if (! is_array($names) || $names === []) {
                continue;
            }

            $target = count($names);

            $controles = DB::table('controles')
                ->where('unite_id', $unite->id)
                ->orderBy('numero')
                ->orderBy('id')
                ->get()
                ->keyBy('numero');

            // Drop the trailing controls the programme does not define, but
            // never one that already carries a mark.
            foreach ($controles as $numero => $controle) {
                if ((int) $numero <= $target) {
                    continue;
                }

                $hasNote = DB::table('notes')->where('controle_id', $controle->id)->exists();
                if (! $hasNote) {
                    DB::table('controles')->where('id', $controle->id)->delete();
                }
            }

            foreach ($names as $index => $name) {
                $numero = $index + 1;
                $existing = $controles->get($numero);

                if ($existing) {
                    // Renamed in place: id (and any mark) is preserved.
                    DB::table('controles')->where('id', $existing->id)->update([
                        'nom'        => $name,
                        'updated_at' => now(),
                    ]);
                    continue;
                }

                DB::table('controles')->insert([
                    'sequence_id' => null,
                    'unite_id'    => $unite->id,
                    'numero'      => $numero,
                    'nom'         => $name,
                    'date'        => null,
                    'note_max'    => 20,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            DB::table('curriculum_corrections')->insert([
                'cible_type'      => 'unites',
                'cible_id'        => $unite->id,
                'champ'           => 'controles',
                'ancienne_valeur' => 'Contrôle 1..N',
                'nouvelle_valeur' => "{$target} services de stage ({$unite->code}{$unite->an})",
                'source'          => 'Programme Des Stages.docx : Lieux des stages',
                'applied_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The former "Contrôle N" labels are not restorable: marks may have been
        // entered while the services were displayed.
    }
};
