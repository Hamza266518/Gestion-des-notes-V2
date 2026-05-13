<?php

namespace App\Imports;

use App\Models\Formateur;
use App\Models\User;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class FormateursImport implements ToCollection, WithHeadingRow
{
    public $created = 0;
    public $updated = 0;
    public $errors  = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['nom']) || empty($row['email'])) continue;

            $user = User::where('email', $row['email'])->first();

            if ($user) {
                $user->update([
                    'name' => $row['nom'],
                ]);
                $this->updated++;
            } else {
                $password = $row['password'] ?? Str::random(12);
                $user = User::create([
                    'name'     => $row['nom'],
                    'email'    => $row['email'],
                    'password' => Hash::make($password),
                    'role'     => 'formateur',
                ]);

                Formateur::create(['user_id' => $user->id]);
                $this->created++;
                $this->errors[] = "{$row['nom']}: mot de passe généré ({$password})";
            }
        }
    }
}