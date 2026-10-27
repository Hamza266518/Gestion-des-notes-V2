<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Builds the login username from a display name: "Mme. Fatima-Zahra TAHRI" -> "fatima.zahra.tahri".
 *
 * Usernames are assigned once and never regenerated afterwards, so that changing a
 * student's or a trainer's display name can never invalidate an existing login.
 */
class UsernameGenerator
{
    public const MAX_LENGTH = 60;

    /**
     * Build a normalized key for duplicate name detection.
     * Lowercase, strip accents/punctuation, sort words.
     */
    public static function nameKey(string $name): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($name)));
        $parts = preg_split('/[\s\-\']+/u', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter($parts, function (string $part) {
            $part = trim($part, " \t\n\r\0\x0B.,-_");
            if ($part === '') {
                return false;
            }
            return preg_match('/[a-z0-9]/', $part) === 1;
        }));
        if ($parts === []) {
            return '';
        }
        sort($parts);
        return implode(' ', $parts);
    }
    public const ADMIN_USERNAME = 'admin';
    public const FALLBACK = 'utilisateur';

    private const HONORIFICS = ['m', 'mr', 'mrs', 'mme', 'mlle', 'dr', 'pr', 'prof'];

    /**
     * Deterministic `name.surname` base, without collision handling.
     */
    public function baseFromName(string $name): string
    {
        $parts = preg_split('/[\s]+/u', Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = array_values(array_filter(
            $parts,
            fn (string $part) => $this->isUsablePart($part)
        ));

        if ($parts === []) {
            return self::FALLBACK;
        }

        $slug = mb_strtolower(implode('.', $parts));

        // Hyphens, apostrophes and any other separator become dots, never disappear:
        // "Fatima-Zahra" -> "fatima.zahra", "L night's" -> "l.night.s".
        $slug = preg_replace('/[^a-z0-9]+/u', '.', $slug) ?? '';
        $slug = trim(preg_replace('/\.{2,}/', '.', $slug) ?? '', '.');

        if ($slug === '') {
            return self::FALLBACK;
        }

        return Str::limit($slug, self::MAX_LENGTH, '');
    }

    /**
     * Reserve a free username for $base, appending 2, 3, ... until it is available.
     */
    public function reserve(string $base, ?int $ignoreUserId = null): string
    {
        $base = $this->truncate($base);
        $candidate = $base;
        $counter = 1;

        while ($this->isTaken($candidate, $ignoreUserId)) {
            $counter++;
            $candidate = $this->truncate($base, self::MAX_LENGTH - strlen((string) $counter)) . $counter;
        }

        return $candidate;
    }

    /**
     * Reserve the username that should be given to a brand new account.
     */
    public function reserveFor(string $name, string $role): string
    {
        $base = $role === 'admin' ? self::ADMIN_USERNAME : $this->baseFromName($name);

        return $this->reserve($base);
    }

    public function forNewUser(User $user): string
    {
        return $this->reserveFor((string) $user->name, (string) $user->role);
    }

    public function isValidFormat(?string $username): bool
    {
        return is_string($username)
            && $username !== ''
            && strlen($username) <= self::MAX_LENGTH
            && (bool) preg_match('/^[a-z0-9]+(?:\.[a-z0-9]+)*$/', $username);
    }

    public function isTaken(string $username, ?int $ignoreUserId = null): bool
    {
        $query = User::where('username', $username);

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }

    private function truncate(string $value, ?int $length = null): string
    {
        $length ??= self::MAX_LENGTH;
        $value = rtrim(trim($value), '.');

        return $value === '' ? self::FALLBACK : Str::limit($value, $length, '');
    }

    private function isUsablePart(string $part): bool
    {
        $part = trim($part, " \t\n\r\0\x0B.,-_");

        if ($part === '' || in_array(mb_strtolower($part), self::HONORIFICS, true)) {
            return false;
        }

        return preg_match('/[a-z0-9]/i', $part) === 1;
    }
}