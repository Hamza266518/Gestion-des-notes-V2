<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Filiere extends Model
{
    protected $fillable = [
        'nom',
        'nom_ar',
        'code',
        'section',
        'type_formation',
        'nombre_annees',
    ];

    public function niveaux()
    {
        return $this->hasMany(Niveau::class);
    }

    public function unites()
    {
        return $this->hasMany(Unite::class);
    }
}