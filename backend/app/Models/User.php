<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'password_encrypted',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'password_encrypted',
    ];

    public function getPasswordPlainAttribute(): ?string
    {
        return $this->password_encrypted ? Crypt::decryptString($this->password_encrypted) : null;
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