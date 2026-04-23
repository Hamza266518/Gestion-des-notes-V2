<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SemestrePublication extends Model
{
    protected $fillable = [
        'groupe_id',
        'annee_academique_id',
        'semestre',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function groupe()
    {
        return $this->belongsTo(Groupe::class);
    }

    public function anneeAcademique()
    {
        return $this->belongsTo(AnneeAcademique::class);
    }
}