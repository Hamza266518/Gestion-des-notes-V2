<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Controle extends Model
{
    protected $fillable = [
        'sequence_id',
        'unite_id',
        'numero',
        'nom',
        'date',
        'note_max',
        'formateur_id',
    ];

    public function sequence()
    {
        return $this->belongsTo(Sequence::class);
    }

    public function unite()
    {
        return $this->belongsTo(Unite::class);
    }

    public function formateur()
    {
        return $this->belongsTo(User::class, 'formateur_id');
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }
}