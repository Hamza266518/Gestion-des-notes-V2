<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Examen extends Model
{
    use HasFactory;

    protected $fillable = [
        'etudiant_id',
        'unite_id',
        'bloc',
        'type',
        'semestre',
        'annee_academique_id',
        'valeur',
    ];

    protected $casts = [
        'valeur' => 'float',
    ];

    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function unite()
    {
        return $this->belongsTo(Unite::class);
    }

    public function anneeAcademique()
    {
        return $this->belongsTo(AnneeAcademique::class);
    }
}
