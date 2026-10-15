<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row settings table (id 1) holding the establishment-level values
 * printed inside the diploma's certification text and their calibrated marker
 * positions (per template type).
 */
class DiplomeParametre extends Model
{
    protected $fillable = [
        'valeurs',
        'positions',
    ];

    protected $casts = [
        'valeurs'   => 'array',
        'positions' => 'array',
    ];

    /**
     * The settings row, created on first use so callers never have to seed it.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
