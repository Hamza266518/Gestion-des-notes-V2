<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScanNameCache extends Model
{
    protected $table = 'scan_name_cache';

    protected $fillable = [
        'raw_nom',
        'raw_nom_ar',
        'raw_nom_normalized',
        'etudiant_id',
        'formateur_id',
    ];

    public function etudiant()
    {
        return $this->belongsTo(Etudiant::class);
    }

    public static function normalizeName(string $name): string
    {
        $words = preg_split('/\s+/', trim(mb_strtolower($name)));
        $words = array_filter($words);
        sort($words);
        return implode(' ', $words);
    }
}
