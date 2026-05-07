<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnneeAcademique extends Model
{
    protected $table = 'annees_academiques';

    protected $fillable = [
        'label',
        'start_date',
        'end_date',
        'is_current',
        'is_archived',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
        'is_archived' => 'boolean',
    ];

    public function groupes()
    {
        return $this->hasMany(Groupe::class);
    }

    public function etudiants()
    {
        return $this->hasMany(Etudiant::class);
    }

    public function publications()
    {
        return $this->hasMany(SemestrePublication::class);
    }

    public function diplomes()
    {
        return $this->hasMany(Diplome::class);
    }
}