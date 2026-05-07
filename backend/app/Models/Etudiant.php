<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Etudiant extends Model
{
    protected $fillable = [
        'user_id', 'groupe_id', 'annee_academique_id', 'status',
        'nom_prenom', 'nom_ar', 'cin', 'numero_inscription',
        'date_naissance', 'lieu_naissance', 'nationalite', 'date_inscription',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function groupe()
    {
        return $this->belongsTo(Groupe::class);
    }

    public function anneeAcademique()
    {
        return $this->belongsTo(AnneeAcademique::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function diplome()
    {
        return $this->hasOne(Diplome::class);
    }
}