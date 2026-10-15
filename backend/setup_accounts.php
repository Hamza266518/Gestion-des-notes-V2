<?php
/**
 * One-off maintenance script: (re)assign student logins and reset their passwords.
 *
 * Identifiers are generated from the student name by App\Services\UsernameGenerator
 * ("FATIMA ZAHRA IDRISSI" -> "fatima.zahra.idrissi", admin stays "admin").
 */
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Etudiant;
use App\Services\UsernameGenerator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

$upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$lower = 'abcdefghijklmnopqrstuvwxyz';
$digits = '0123456789';
$symbols = '@$!%*?&-_.+';
$allChars = $upper . $lower . $digits . $symbols;

function generatePassword(string $cin): string {
    global $upper, $lower, $digits, $symbols, $allChars;
    $cinLen = strlen($cin);
    $randomLen = max(4, 12 - $cinLen);
    $random = $upper[random_int(0, 25)] .
              $lower[random_int(0, 25)] .
              $digits[random_int(0, 9)] .
              $symbols[random_int(0, strlen($symbols) - 1)];
    for ($i = 4; $i < $randomLen; $i++) {
        $random .= $allChars[random_int(0, strlen($allChars) - 1)];
    }
    return $cin . str_shuffle($random);
}

$generator = app(UsernameGenerator::class);

$students = DB::table('etudiants')
    ->join('users', 'etudiants.user_id', '=', 'users.id')
    ->select('etudiants.id as etudiant_id', 'etudiants.nom_prenom', 'etudiants.cin', 'users.id as user_id')
    ->orderBy('etudiants.id')
    ->get();

echo "Processing " . count($students) . " students...\n\n";

$updated = 0;
foreach ($students as $s) {
    $username = $generator->reserve($generator->baseFromName($s->nom_prenom), (int) $s->user_id);
    $password = generatePassword($s->cin);

    DB::table('users')->where('id', $s->user_id)->update([
        'name' => $s->nom_prenom,
        'username' => $username,
        'email' => null,
        'password' => Hash::make($password),
        'password_encrypted' => Crypt::encryptString($password),
        'password_original_encrypted' => Crypt::encryptString($password),
        'password_changed_at' => null,
    ]);

    echo str_pad($s->nom_prenom, 40) . ' | ' . str_pad($username, 35) . ' | ' . $password . "\n";
    $updated++;
}

echo "\nDone! $updated accounts updated.\n";