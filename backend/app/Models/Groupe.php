<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Groupe extends Model
{
    protected $fillable = [
        'niveau_id',
        'annee_academique_id',
        'nom',
        'promotion',
    ];

    public function niveau()
    {
        return $this->belongsTo(Niveau::class);
    }

    public function anneeAcademique()
    {
        return $this->belongsTo(AnneeAcademique::class);
    }

    public function etudiants()
    {
        return $this->hasMany(Etudiant::class);
    }

    public function publications()
    {
        return $this->hasMany(SemestrePublication::class);
    }
}