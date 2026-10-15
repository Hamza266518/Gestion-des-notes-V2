<?php

use App\Services\SoutenanceService;
use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The programmes that end with a jury présentation (AS an1, IA an2, IP an3) get
 * a "Soutenance" unit holding the ten NSTI criteria as its direct controls. The
 * admin grades them in the ordinary notes screen ("manually, like the Stage");
 * nothing is derived from a grid.
 *
 * The unit is excluded from every MPCC / théorique / pratique table and average:
 * it only feeds the NSTI component of the final mark.
 *
 * Idempotent: a filière/année that already owns an active "Soutenance" unit is
 * left untouched, so re-running never duplicates a unit or a criterion.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (SoutenanceService::PROGRAMMES as $programme) {
            $filiere = DB::table('filieres')->where('code', $programme['code'])->first();
            if (! $filiere) {
                continue;
            }

            $numeroAnnee = (int) $programme['annee'];

            $exists = DB::table('unites')
                ->where('nom', SoutenanceService::UNITE_NOM)
                ->where('filiere_id', $filiere->id)
                ->where('numero_annee', $numeroAnnee)
                ->where('is_active', true)
                ->exists();

            if ($exists) {
                continue;
            }

            $ordre = (int) DB::table('unites')
                ->where('filiere_id', $filiere->id)
                ->where('numero_annee', $numeroAnnee)
                ->max('ordre') + 1;

            $uniteId = DB::table('unites')->insertGetId([
                'filiere_id'   => $filiere->id,
                'nom'          => SoutenanceService::UNITE_NOM,
                'coefficient'  => 1,
                'numero_annee' => $numeroAnnee,
                'semestre'     => 2,
                'ordre'        => $ordre,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            foreach (OfficialCurriculum::NSTI_CRITERIA as $index => $criterion) {
                DB::table('controles')->insert([
                    'sequence_id' => null,
                    'unite_id'    => $uniteId,
                    'numero'      => $index + 1,
                    'nom'         => $criterion['nom'],
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
                'ancienne_valeur' => 'aucune unité Soutenance',
                'nouvelle_valeur' => count(OfficialCurriculum::NSTI_CRITERIA)
                    . " critères du jury ({$programme['code']}{$numeroAnnee})",
                'source'          => 'Relevé de notes IP3 : Critères arrêtés par le jury d\'examens',
                'applied_at'      => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deleting the unit would destroy the criteria marks it may already
        // hold. Deactivate it (is_active = 0) instead of dropping the rows.
    }
};
