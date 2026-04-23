<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    protected $fillable = [
        'unite_id',
        'nom',
        'coefficient',
        'nombre_controles',
        'ordre',
        'is_active',
    ];

    public function unite()
    {
        return $this->belongsTo(Unite::class);
    }

    public function controles()
    {
        return $this->hasMany(Controle::class)->orderBy('numero');
    }
}