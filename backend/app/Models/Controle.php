<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Controle extends Model
{
    protected $fillable = [
        'sequence_id',
        'numero',
        'date',
        'note_max',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }
}