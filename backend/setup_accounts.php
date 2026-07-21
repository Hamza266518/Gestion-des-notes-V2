<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\Etudiant;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

$prefixes = ['el', 'al', 'ben', 'bin'];
$symbols = '@$!%*?&-_.+';
$upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
$lower = 'abcdefghijklmnopqrstuvwxyz';
$digits = '0123456789';
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

function isAllCaps(string $s): bool {
    return preg_match('/^[A-Z]+$/', $s) && mb_strlen($s, 'UTF-8') > 0;
}

function getPrefixes(): array {
    return ['el', 'al', 'ben', 'bin'];
}

function processSurnameTokens(array $tokens): string {
    $prefixes = getPrefixes();
    $all = [];
    foreach ($tokens as $t) {
        $t = mb_strtolower(trim($t));
        $t = str_replace("'", '', $t);
        foreach (explode('-', $t) as $sub) {
            if ($sub !== '') $all[] = $sub;
        }
    }
    $segments = [];
    $i = 0;
    while ($i < count($all)) {
        if (in_array($all[$i], $prefixes) && $i + 1 < count($all)) {
            $segments[] = $all[$i] . $all[$i + 1];
            $i += 2;
        } else {
            $segments[] = $all[$i];
            $i++;
        }
    }
    return implode('.', $segments);
}

function processGivenTokens(array $tokens): string {
    $result = '';
    foreach ($tokens as $t) {
        $t = mb_strtolower(trim($t));
        $t = str_replace(["'", '-'], '', $t);
        $result .= $t;
    }
    return $result;
}

function nameToEmail(string $nomPrenom): string {
    $words = preg_split('/\s+/', trim($nomPrenom));
    if (count($words) === 0) return '';

    // If ALL words are ALL-CAPS, treat as Given-first (first=given, last=surname)
    $allCaps = array_reduce($words, fn($carry, $w) => $carry && isAllCaps($w), true);
    $isSurnameFirst = !$allCaps && isAllCaps($words[0]);

    if ($isSurnameFirst) {
        $splitAt = 0;
        foreach ($words as $w) {
            if (isAllCaps($w)) $splitAt++;
            else break;
        }
        // If only the first word is ALL-CAPS and remaining words contain
        // hyphens/apostrophes, treat as Given-first (ALL-CAPS first name)
        if ($splitAt === 1) {
            $remaining = array_slice($words, 1);
            $hasSpecial = array_reduce($remaining, fn($c, $w) => $c || preg_match("/[-']/", $w), false);
            if ($hasSpecial) {
                $isSurnameFirst = false;
            }
        }
    }
    if ($isSurnameFirst) {
        $splitAt = 0;
        foreach ($words as $w) {
            if (isAllCaps($w)) $splitAt++;
            else break;
        }
        $surnameTokens = array_slice($words, 0, $splitAt);
        $givenTokens = array_slice($words, $splitAt);
    } else {
        $lastIdx = count($words) - 1;
        $surnameTokens = [$words[$lastIdx]];
        for ($i = $lastIdx - 1; $i >= 0; $i--) {
            $lower = mb_strtolower($words[$i], 'UTF-8');
            if (in_array($lower, getPrefixes())) {
                array_unshift($surnameTokens, $words[$i]);
            } else {
                break;
            }
        }
        $givenTokens = array_slice($words, 0, count($words) - count($surnameTokens));
    }

    if (count($givenTokens) === 0) $givenTokens = $surnameTokens;
    if (count($surnameTokens) === 0) $surnameTokens = $givenTokens;

    $givenPart = processGivenTokens($givenTokens);
    $surnamePart = processSurnameTokens($surnameTokens);

    return $givenPart . '.' . $surnamePart . '@ifp.ma';
}

$students = DB::table('etudiants')
    ->join('users', 'etudiants.user_id', '=', 'users.id')
    ->select('etudiants.id as etudiant_id', 'etudiants.nom_prenom', 'etudiants.cin', 'users.id as user_id')
    ->orderBy('etudiants.id')
    ->get();

echo "Processing " . count($students) . " students...\n\n";

$updated = 0;
foreach ($students as $s) {
    $email = nameToEmail($s->nom_prenom);
    $password = generatePassword($s->cin);

    // Ensure unique email (shouldn't happen, but just in case)
    $originalEmail = $email;
    $counter = 2;
    while (DB::table('users')->where('email', $email)->where('id', '!=', $s->user_id)->exists()) {
        $localPart = explode('@', $originalEmail)[0];
        $email = $localPart . $counter . '@ifp.ma';
        $counter++;
    }

    DB::table('users')->where('id', $s->user_id)->update([
        'name' => $s->nom_prenom,
        'email' => $email,
        'password' => Hash::make($password),
        'password_encrypted' => Crypt::encryptString($password),
        'password_original_encrypted' => Crypt::encryptString($password),
        'password_changed_at' => null,
    ]);

    echo str_pad($s->nom_prenom, 40) . ' | ' . str_pad($email, 35) . ' | ' . $password . "\n";
    $updated++;
}

echo "\nDone! $updated accounts updated.\n";
