<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unite extends Model
{
    protected $fillable = [
        'filiere_id',
        'nom',
        'coefficient',
        'numero_annee',
        'semestre',
        'ordre',
        'is_active',
    ];

    public function filiere()
    {
        return $this->belongsTo(Filiere::class);
    }

    public function sequences()
    {
        return $this->hasMany(Sequence::class)->orderBy('ordre');
    }

    public function formateurs()
    {
        return $this->belongsToMany(Formateur::class, 'formateur_unites');
    }
}