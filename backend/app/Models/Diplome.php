<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diplome extends Model
{
    protected $fillable = [
        'etudiant_id',
        'annee_academique_id',
        'moyenne_generale',
        'mention',
        'is_printed',
        'printed_at',
    ];

    protected $casts = [
        'is_printed' => 'boolean',
        'printed_at' => 'datetime',
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