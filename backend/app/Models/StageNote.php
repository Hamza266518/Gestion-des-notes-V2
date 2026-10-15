<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-service "Examen" mark of a Stage unit. The Évaluation side of the
 * same service lives in `notes` (one row per student and control); because
 * `notes` is unique on (etudiant_id, controle_id), the Examen mark has its own
 * table, unique on (etudiant_id, controle_id, type).
 */
class StageNote extends Model
{
    public const EXAMEN_TYPE = 'grille_examen';

    protected $fillable = [
        'etudiant_id',
        'controle_id',
        'type',
        'valeur',
        'est_absent',
    ];

    protected $casts = [
        'valeur'     => 'float',
        'est_absent' => 'boolean',
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