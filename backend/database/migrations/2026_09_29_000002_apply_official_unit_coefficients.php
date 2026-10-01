<?php

use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE = 'word/Tableau des Coefficient des unités.docx';

    public function up(): void
    {
        $official = OfficialCurriculum::coefficients();
        $unresolved = OfficialCurriculum::unresolved();
        $matched = [];
        $changes = 0;

        $unites = DB::table('unites')
            ->join('filieres', 'filieres.id', '=', 'unites.filiere_id')
            ->orderBy('unites.id')
            ->get(['unites.id', 'unites.nom', 'unites.coefficient', 'unites.numero_annee', 'filieres.code']);

        DB::transaction(function () use ($unites, $official, $unresolved, &$matched, &$changes) {
            foreach ($unites as $u) {
                $code = $u->code;
                $annee = (int) $u->numero_annee;
                $key = "{$code}:{$annee}";

                if (isset($unresolved[$key])) {
                    continue;
                }

                $list = $official[$code][$annee] ?? null;
                if (!is_array($list)) {
                    continue;
                }

                $target = OfficialCurriculum::normalise($u->nom);
                $alias = OfficialCurriculum::aliases()[$code][$annee][$u->nom] ?? null;
                if ($alias !== null) {
                    $target = OfficialCurriculum::normalise($alias[0]);
                }

                $expected = null;
                $expectedNom = null;
                foreach ($list as $idx => $o) {
                    if (!isset($matched["{$code}|{$annee}|{$idx}"]) && OfficialCurriculum::normalise($o['nom']) === $target) {
                        $expected = $o['coefficient'];
                        $expectedNom = $o['nom'];
                        $matched["{$code}|{$annee}|{$idx}"] = true;
                        break;
                    }
                }

                if ($expected === null) {
                    continue;
                }

                if ((int) $u->coefficient === $expected) {
                    continue;
                }

                DB::table('unites')->where('id', $u->id)->update(['coefficient' => $expected]);
                DB::table('curriculum_corrections')->insert([
                    'cible_type' => 'unites',
                    'cible_id' => $u->id,
                    'champ' => 'coefficient',
                    'ancienne_valeur' => (string) $u->coefficient,
                    'nouvelle_valeur' => (string) $expected,
                    'source' => self::SOURCE . ' :: ' . $expectedNom,
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $changes++;
            }
        });
    }

    public function down(): void
    {
        $rows = DB::table('curriculum_corrections')
            ->where('cible_type', 'unites')
            ->where('champ', 'coefficient')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($rows) {
            foreach ($rows as $r) {
                DB::table('unites')->where('id', $r->cible_id)->update(['coefficient' => (int) $r->ancienne_valeur]);
            }
            DB::table('curriculum_corrections')
                ->where('cible_type', 'unites')
                ->where('champ', 'coefficient')
                ->delete();
        });
    }
};
