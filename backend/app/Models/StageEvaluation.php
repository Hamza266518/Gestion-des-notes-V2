<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StageEvaluation extends Model
{
    protected $fillable = [
        'etudiant_id',
        'annee_academique_id',
        'type',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function anneeAcademique()
    {
        return $this->belongsTo(AnneeAcademique::class);
    }
}
