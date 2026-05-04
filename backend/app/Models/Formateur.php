<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Formateur extends Model
{
    protected $fillable = ['user_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function sequences()
    {
        return $this->belongsToMany(Sequence::class, 'formateur_sequences')
                    ->withPivot('masse_horaire')
                    ->withTimestamps();
    }

    public function unitesViaSequences()
    {
        return $this->belongsToMany(Unite::class, 'formateur_sequences', 'formateur_id', 'sequence_id')
                    ->join('sequences', 'sequences.id', '=', 'formateur_sequences.sequence_id')
                    ->join('unites', 'unites.id', '=', 'sequences.unite_id')
                    ->select('unites.*')
                    ->distinct();
    }

    public function scanLogs()
    {
        return $this->hasMany(ScanLog::class);
    }
}