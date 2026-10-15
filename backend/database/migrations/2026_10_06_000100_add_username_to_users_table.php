<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the login identifier from the synthetic `<something>@ifp.ma` address to a
 * dedicated `username`, and adds the optional real contact email.
 *
 * `email` is kept (nullable) so a rollback restores the previous values, but it is
 * no longer written by the application.
 */
return new class extends Migration
{
    private const SYNTHETIC_DOMAIN = '@ifp.ma';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->after('name');
            $table->string('contact_email', 190)->nullable()->after('email');
            $table->timestamp('contact_email_verified_at')->nullable()->after('contact_email');
        });

        // Must happen before the backfill empties the synthetic addresses.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        DB::table('users')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update([
                    'username' => $this->reserveUsername($row),
                    'email'    => $this->isSynthetic($row->email) ? null : $row->email,
                ]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable(false)->change();
            $table->unique('username');
            $table->unique('contact_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['contact_email']);
            $table->dropColumn(['username', 'contact_email', 'contact_email_verified_at']);
            $table->string('email')->nullable(false)->change();
        });
    }

    /**
     * Intentionally self-contained: a data migration must produce the same usernames
     * forever, independently of later changes to App\Services\UsernameGenerator.
     */
    private function reserveUsername(object $row): string
    {
        $base = $row->role === 'admin'
            ? 'admin'
            : $this->slug((string) $row->name);

        $candidate = $base;
        $counter = 1;

        while (DB::table('users')->where('username', $candidate)->where('id', '!=', $row->id)->exists()) {
            $counter++;
            $candidate = mb_substr($base, 0, 60 - strlen((string) $counter)) . $counter;
        }

        return $candidate;
    }

    private function slug(string $name): string
    {
        $honorifics = ['m', 'mr', 'mrs', 'mme', 'mlle', 'dr', 'pr', 'prof'];

        $parts = preg_split('/[\s]+/u', \Illuminate\Support\Str::ascii($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $parts = array_values(array_filter($parts, function (string $part) use ($honorifics) {
            $part = trim($part, " \t\n\r\0\x0B.,-_");

            return $part !== ''
                && !in_array(mb_strtolower($part), $honorifics, true)
                && preg_match('/[a-z0-9]/i', $part) === 1;
        }));

        if ($parts === []) {
            return 'utilisateur';
        }

        $slug = mb_strtolower(implode('.', $parts));
        $slug = preg_replace('/[^a-z0-9]+/u', '.', $slug) ?? '';
        $slug = trim(preg_replace('/\.{2,}/', '.', $slug) ?? '', '.');

        if ($slug === '') {
            return 'utilisateur';
        }

        return mb_substr($slug, 0, 60);
    }

    private function isSynthetic(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower($email), self::SYNTHETIC_DOMAIN);
    }
};