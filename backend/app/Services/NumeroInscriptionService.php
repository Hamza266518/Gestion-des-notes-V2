<?php

namespace App\Services;

use App\Models\Etudiant;
use Illuminate\Support\Facades\DB;

class NumeroInscriptionService
{
    public function generate(string $filiereCode, int $anneeAcademiqueId): string
    {
        return DB::transaction(function () use ($filiereCode, $anneeAcademiqueId) {
            $annee = \App\Models\AnneeAcademique::findOrFail($anneeAcademiqueId);
            $year  = substr(explode('/', $annee->label)[1], -2);

            $count = Etudiant::where('annee_academique_id', $anneeAcademiqueId)
                ->whereHas('groupe.niveau.filiere', fn($q) => $q->where('code', $filiereCode))
                ->lockForUpdate()
                ->count();

            $numero = str_pad($count + 1, 2, '0', STR_PAD_LEFT);

            return $numero . $filiereCode . $year;
        });
    }
}
