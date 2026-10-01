<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    protected $fillable = [
        'etudiant_id',
        'controle_id',
        'valeur',
        'est_absent',
        'chemin_image',
        'is_confirmed',
        'confirmed_at',
    ];

    protected $casts = [
        'valeur' => 'float',
        'est_absent' => 'boolean',
        'is_confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
    ];

    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }

    public function controle()
    {
        return $this->belongsTo(Controle::class);
    }
}