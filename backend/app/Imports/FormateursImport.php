<?php

namespace App\Imports;

use App\Models\Formateur;
use App\Models\User;
use App\Services\UsernameGenerator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class FormateursImport implements ToCollection, WithHeadingRow
{
    public $created = 0;
    public $updated = 0;
    public $errors  = [];

    public function collection(Collection $rows)
    {
        $generator = app(UsernameGenerator::class);

        foreach ($rows as $row) {
            if (empty($row['nom'])) continue;

            $username = $this->resolveUsername($row, $generator);

            $user = User::where('username', $username)->first();

            if ($user) {
                $user->update(['name' => $row['nom']]);
                $this->updated++;
            } else {
                $password = $row['password'] ?? User::generateStrongPassword();
                $user = User::create([
                    'name'               => $row['nom'],
                    'username'           => $username,
                    'password'           => Hash::make($password),
                    'password_encrypted' => Crypt::encryptString($password),
                    'password_original_encrypted' => Crypt::encryptString($password),
                    'role'               => 'formateur',
                ]);

                Formateur::create(['user_id' => $user->id]);
                $this->created++;
                \Log::info("Formateur import: {$row['nom']} created (logged without password)");
            }
        }
    }

    /**
     * Accepts an `identifiant`/`username` column, or the legacy `email` column whose
     * local part is the identifier ("m.alami@ifp.ma" -> "m.alami").
     */
    private function resolveUsername(mixed $row, UsernameGenerator $generator): string
    {
        $candidate = $row['username'] ?? $row['identifiant'] ?? $row['email'] ?? null;

        if (is_string($candidate) && str_contains($candidate, '@')) {
            $candidate = strstr($candidate, '@', true);
        }

        if (is_string($candidate)) {
            $candidate = mb_strtolower(trim($candidate));

            if ($generator->isValidFormat($candidate)) {
                return $candidate;
            }
        }

        return $generator->reserveFor((string) $row['nom'], 'formateur');
    }
}