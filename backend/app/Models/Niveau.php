<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Niveau extends Model
{
    protected $fillable = [
        'filiere_id',
        'numero',
    ];

    public function filiere()
    {
        return $this->belongsTo(Filiere::class);
    }

    public function groupes()
    {
        return $this->hasMany(Groupe::class);
    }
}