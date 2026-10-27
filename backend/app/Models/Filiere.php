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

    protected $appends = ['type_formation_ar', 'type_formation_fr', 'type_formation_effective'];

    public function getTypeFormationEffectiveAttribute()
    {
        $section = strtoupper(trim((string) $this->section));
        if (in_array($section, ['Q', 'QUALIFICATION'], true)) {
            return 'Qualification';
        }
        if (in_array($section, ['T', 'TECHNICIEN'], true)) {
            return 'Technicien';
        }
        if (in_array($section, ['TS', 'SPECIALISATION', 'TECHNICIEN SPECIALISE'], true)) {
            return 'Specialisation';
        }
        $type = $this->type_formation;
        if ($type) {
            return $type;
        }
        return null;
    }

    public function getTypeFormationFrAttribute()
    {
        $type = $this->type_formation_effective;
        return [
            'Qualification' => 'Qualification',
            'Technicien'    => 'Technicien',
            'Specialisation'=> 'Technicien Spécialisé',
        ][$type] ?? '';
    }

    public function getTypeFormationArAttribute()
    {
        $type = $this->type_formation_effective;
        return [
            'Qualification' => 'تأهيل',
            'Technicien'    => 'تقني',
            'Specialisation'=> 'تقني متخصص',
        ][$type] ?? '';
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