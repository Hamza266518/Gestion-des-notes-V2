<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EtudiantPasswordReset extends Model
{
    protected $table = 'etudiant_password_resets';

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
        'ip_address',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at'   => 'datetime',
    ];

    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
