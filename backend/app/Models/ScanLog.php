<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanLog extends Model
{
    protected $fillable = [
        'formateur_id',
        'chemin_image',
        'texte_brut',
        'statut',
    ];

    public function formateur()
    {
        return $this->belongsTo(Formateur::class);
    }
}