<?php

namespace App\Models;

use App\Services\UsernameGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'contact_email',
        'contact_email_verified_at',
        'password',
        'password_encrypted',
        'password_original_encrypted',
        'password_changed_at',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_encrypted',
        'password_original_encrypted',
    ];

    protected $casts = [
        'contact_email_verified_at' => 'datetime',
        'password_changed_at'        => 'datetime',
    ];

    protected static function booted(): void
    {
        // Guarantees the mandatory unique username, whichever code path creates the account.
        static::creating(function (self $user) {
            if (blank($user->username)) {
                $user->username = app(UsernameGenerator::class)->forNewUser($user);
            }
        });

        // The login identifier is assigned once and stays put: it may only be changed
        // directly in the database, never through the application (mass assignment,
        // account API, admin forms).
        static::updating(function (self $user) {
            if ($user->isDirty('username')) {
                $user->username = $user->getOriginal('username');
            }
        });
    }

    /**
     * Only a verified contact email may be used to recover the account.
     */
    public function hasVerifiedContactEmail(): bool
    {
        return filled($this->contact_email)
            && filled($this->contact_email_verified_at);
    }

    public function authCodes()
    {
        return $this->hasMany(AuthCode::class);
    }

    public function getPasswordPlainAttribute(): ?string
    {
        if (!$this->password_encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password_encrypted);
        } catch (\Exception $e) {
            // A single unreadable row must not break the whole admin listing
            return null;
        }
    }

    /**
     * Reveal the plaintext password on an admin-only response.
     * Never appended globally: this must be called explicitly per endpoint.
     */
    public function withPlainPassword(): static
    {
        $this->append('password_plain');

        return $this;
    }

    public static function generateStrongPassword(int $length = 14): string
    {
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $digits = '0123456789';
        $symbols = '@$!%*?&-_.+';

        $password =
            $lower[random_int(0, 25)] .
            $upper[random_int(0, 25)] .
            $digits[random_int(0, 9)] .
            $symbols[random_int(0, strlen($symbols) - 1)];

        $all = $lower . $upper . $digits . $symbols;
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }

    public function formateur()
    {
        return $this->hasOne(Formateur::class);
    }

    public function etudiant()
    {
        return $this->hasOne(Etudiant::class);
    }
}