<?php

namespace App\Services;

use App\Models\Etudiant;

class NumeroInscriptionService
{
    public function generate(string $filiereCode, int $anneeAcademiqueId): string
    {
        $annee = \App\Models\AnneeAcademique::findOrFail($anneeAcademiqueId);
        $year  = substr(explode('/', $annee->label)[1], -2); // "2025/2026" → "26"

        $count = Etudiant::where('annee_academique_id', $anneeAcademiqueId)
            ->whereHas('groupe.niveau.filiere', fn($q) => $q->where('code', $filiereCode))
            ->count();

        $numero = str_pad($count + 1, 2, '0', STR_PAD_LEFT);

        return $numero . $filiereCode . $year; // "01AS26"
    }
}