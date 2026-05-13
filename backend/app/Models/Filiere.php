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

    protected $appends = ['type_formation_ar', 'type_formation_fr'];

    public function getTypeFormationFrAttribute()
    {
        return [
            'Qualification' => 'Qualification',
            'Technicien'    => 'Technicien',
            'Specialisation'=> 'Technicien Spécialisé',
        ][$this->type_formation] ?? '';
    }

    public function getTypeFormationArAttribute()
    {
        return [
            'Qualification' => 'تأهيل',
            'Technicien'    => 'تقني',
            'Specialisation'=> 'تقني متخصص',
        ][$this->type_formation] ?? '';
    }

    public function niveaux()
    {
        return $this->hasMany(Niveau::class);
    }

    public function unites()
    {
        return $this->hasMany(Unite::class);
    }
}