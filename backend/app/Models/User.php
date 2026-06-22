<?php

namespace App\Models;

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
        'email',
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

    public function getPasswordPlainAttribute(): ?string
    {
        return $this->password_encrypted ? Crypt::decryptString($this->password_encrypted) : null;
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