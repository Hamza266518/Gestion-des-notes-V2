<?php

use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Applies the structural rules stated in the official documents:
 *
 *  1. IA2 "Démographie et espacement des naissances et système d'information"
 *     is ONE unit of coefficient 3. The database splits it into 452 (Système
 *     d'information) and 454 (Démographie). The sequences of 452 are reattached
 *     to 454, 454 becomes the single unit with coefficient 3, and 452 is
 *     deactivated rather than deleted so no grade history is lost.
 *
 *  2. Units that an official table defines under a different year keep their
 *     place but receive the documented coefficient.
 *
 *  3. Every programme/year gains the "Stage" unit with coefficient 4, as
 *     required by all six official tables.
 */
return new class extends Migration
{
    /** Marks the audit rows owned by this migration so down() stays scoped. */
    private const PREFIX = '[structure]';

    public function up(): void
    {
        DB::transaction(function () {
            $this->mergeIa2DemographieSystemeInformation();
            $this->applyCrossYearCoefficients();
            $this->createStageUnits();
        });
    }

    private function log(string $type, int $id, string $champ, ?string $old, ?string $new, string $source): void
    {
        DB::table('curriculum_corrections')->insert([
            'cible_type' => $type,
            'cible_id' => $id,
            'champ' => $champ,
            'ancienne_valeur' => $old,
            'nouvelle_valeur' => $new,
            'source' => self::PREFIX . ' ' . $source,
            'applied_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function mergeIa2DemographieSystemeInformation(): void
    {
        $source = 'word/Tableau des Coefficient des unités.docx :: table IA2 :: '
            . "Démographie et espacement des naissances et système d'information (coef 3)";

        $ia2 = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->where('filieres.code', 'IA')
            ->where('unites.numero_annee', 2)
            ->get(['unites.id', 'unites.nom', 'unites.coefficient', 'unites.is_active']);

        $demographie = $ia2->first(fn($u) => OfficialCurriculum::normalise($u->nom)
            === OfficialCurriculum::normalise('Démographie et espacement des naissances'));
        $systeme = $ia2->first(fn($u) => OfficialCurriculum::normalise($u->nom)
            === OfficialCurriculum::normalise("Système d'information"));

        if ($demographie === null || $systeme === null) {
            return; // the split is not present, nothing to merge
        }

        // Reattach every sequence (and therefore every note) of 452 to 454.
        $sequences = DB::table('sequences')->where('unite_id', $systeme->id)->orderBy('ordre')->get();
        $nextOrdre = (int) (DB::table('sequences')->where('unite_id', $demographie->id)->max('ordre') ?? 0);
        foreach ($sequences as $seq) {
            $nextOrdre++;
            DB::table('sequences')->where('id', $seq->id)->update([
                'unite_id' => $demographie->id,
                'ordre' => $nextOrdre,
            ]);
            $this->log('sequences', $seq->id, 'unite_id', (string) $systeme->id, (string) $demographie->id, $source);
            $this->log('sequences', $seq->id, 'ordre', (string) $seq->ordre, (string) $nextOrdre, $source);
        }

        if ((int) $demographie->coefficient !== 3) {
            DB::table('unites')->where('id', $demographie->id)->update(['coefficient' => 3]);
            $this->log('unites', $demographie->id, 'coefficient', (string) $demographie->coefficient, '3', $source);
        }

        // Keep the emptied unit as history instead of deleting grades.
        if ($systeme->is_active) {
            DB::table('unites')->where('id', $systeme->id)->update(['is_active' => false, 'coefficient' => 0]);
            $this->log('unites', $systeme->id, 'is_active', '1', '0', $source);
            $this->log('unites', $systeme->id, 'coefficient', (string) $systeme->coefficient, '0', $source);
        }
    }

    private function applyCrossYearCoefficients(): void
    {
        $ipUnites = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->where('filieres.code', 'IP')
            ->get(['unites.id', 'unites.nom', 'unites.coefficient']);

        foreach (OfficialCurriculum::crossYearUnits() as $nom => $def) {
            $unite = $ipUnites->first(fn($u) => OfficialCurriculum::normalise($u->nom)
                === OfficialCurriculum::normalise($nom));

            if ($unite === null) {
                continue;
            }

            if ((int) $unite->coefficient === $def['coefficient']) {
                continue;
            }

            $source = 'word/Tableau des Coefficient des unités.docx :: table IP' . $def['annee']
                . ' :: ' . $def['nom'] . ' (coef ' . $def['coefficient'] . ')';

            DB::table('unites')->where('id', $unite->id)->update(['coefficient' => $def['coefficient']]);
            $this->log('unites', $unite->id, 'coefficient', (string) $unite->coefficient, (string) $def['coefficient'], $source);
        }
    }

    private function createStageUnits(): void
    {
        $source = 'word/Tableau des Coefficient des unités.docx :: colonne Stage :: coef '
            . OfficialCurriculum::STAGE_COEFFICIENT;

        $scopes = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->get(['filieres.code as code', 'unites.numero_annee'])
            ->map(fn($r) => $r->code . ':' . $r->numero_annee)
            ->unique()
            ->sort()
            ->values();

        foreach ($scopes as $scope) {
            [$code, $annee] = explode(':', $scope);
            $annee = (int) $annee;

            $filiere = DB::table('filieres')->where('code', $code)->first();
            if ($filiere === null) {
                continue;
            }

            $siblings = DB::table('unites')
                ->where('filiere_id', $filiere->id)
                ->where('numero_annee', $annee)
                ->get();

            if ($siblings->contains(fn($u) => OfficialCurriculum::normalise($u->nom) === OfficialCurriculum::normalise('Stage'))) {
                continue;
            }

            $ordre = (int) ($siblings->max('ordre') ?? 0) + 1;

            $id = DB::table('unites')->insertGetId([
                'filiere_id'   => $filiere->id,
                'nom'          => 'Stage',
                'coefficient'  => OfficialCurriculum::STAGE_COEFFICIENT,
                'numero_annee' => $annee,
                'semestre'     => 2,
                'ordre'        => $ordre,
                'is_active'    => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $this->log('unites', $id, 'created', null, "Stage (coef 4) {$scope}", $source);
        }
    }

    public function down(): void
    {
        DB::transaction(function () {
            $mine = DB::table('curriculum_corrections')
                ->where('source', 'like', self::PREFIX . '%')
                ->orderBy('id')
                ->get();

            // 1. remove the Stage units this migration created
            foreach ($mine->where('champ', 'created') as $r) {
                DB::table('unites')->where('id', $r->cible_id)->delete();
            }

            // 2. put the merged sequences back where they were
            foreach ($mine->where('champ', 'unite_id') as $r) {
                DB::table('sequences')->where('id', $r->cible_id)->update([
                    'unite_id' => (int) $r->ancienne_valeur,
                ]);
            }
            foreach ($mine->where('champ', 'ordre') as $r) {
                DB::table('sequences')->where('id', $r->cible_id)->update([
                    'ordre' => (int) $r->ancienne_valeur,
                ]);
            }

            // 3. restore coefficients and active flags, oldest correction last
            //    so a value touched twice ends up back at its original
            foreach ($mine->whereIn('champ', ['coefficient', 'is_active'])->sortByDesc('id') as $r) {
                if ($r->champ === 'is_active') {
                    DB::table('unites')->where('id', $r->cible_id)->update([
                        'is_active' => (bool) $r->ancienne_valeur,
                    ]);
                } else {
                    DB::table('unites')->where('id', $r->cible_id)->update([
                        'coefficient' => (int) $r->ancienne_valeur,
                    ]);
                }
            }

            DB::table('curriculum_corrections')
                ->where('source', 'like', self::PREFIX . '%')
                ->delete();
        });
    }
};
