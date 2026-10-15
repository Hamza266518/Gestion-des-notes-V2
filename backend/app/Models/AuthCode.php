<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthCode extends Model
{
    public const PURPOSE_EMAIL_VERIFICATION = 'email_verification';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    protected $table = 'auth_codes';

    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
        'ip_address',
    ];

    protected $casts = [
        'attempts'     => 'integer',
        'max_attempts' => 'integer',
        'expires_at'   => 'datetime',
        'consumed_at'  => 'datetime',
    ];

    public static function hashCode(string $plainCode): string
    {
        return hash('sha256', $plainCode);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}