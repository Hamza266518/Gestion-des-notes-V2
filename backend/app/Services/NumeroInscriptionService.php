<?php

namespace App\Services;

use App\Models\Etudiant;
use Illuminate\Support\Facades\DB;

class NumeroInscriptionService
{
    /**
     * Build the next registration number for a filière/année.
     *
     * The number is derived from how many students already exist, so calling it
     * several times before any insert (a scan batch) would hand back the same
     * value every time. $offset lets a caller reserve the following numbers
     * within one batch: pass 0, 1, 2, ... for the 1st, 2nd, 3rd new student.
     */
    public function generate(string $filiereCode, int $anneeAcademiqueId, int $offset = 0): string
    {
        return DB::transaction(function () use ($filiereCode, $anneeAcademiqueId, $offset) {
            $annee = \App\Models\AnneeAcademique::findOrFail($anneeAcademiqueId);
            $year  = substr(explode('/', $annee->label)[1] ?? '', -2);

            $count = Etudiant::where('annee_academique_id', $anneeAcademiqueId)
                ->whereHas('groupe.niveau.filiere', fn($q) => $q->where('code', $filiereCode))
                ->lockForUpdate()
                ->count();

            $numero = str_pad($count + 1 + max(0, $offset), 2, '0', STR_PAD_LEFT);

            return $numero . $filiereCode . $year;
        });
    }
}
