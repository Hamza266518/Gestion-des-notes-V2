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

    public function unites()
    {
        return $this->belongsToMany(Unite::class, 'formateur_unites');
    }

    public function scanLogs()
    {
        return $this->hasMany(ScanLog::class);
    }
}