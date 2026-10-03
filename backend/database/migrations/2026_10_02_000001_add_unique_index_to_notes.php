<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (student, control) is the invariant every read path assumes.
 *
 * BulletinService picks with `firstWhere('etudiant_id', ...)` and the frontend
 * with `find(...)`, so when two rows exist for the same pair the displayed grade
 * depends on which one the database happens to return first. ScanController used
 * `updateOrCreate`, a SELECT followed by an INSERT, so two concurrent confirms for
 * the same student and control both saw "nothing there yet" and both inserted.
 *
 * The unique index turns that race from silent corruption into a loud failure.
 * The `examens` table already carries the equivalent constraint, which is why only
 * grades were affected.
 *
 * Verified before writing this migration: 0 duplicate pairs across 5,967 rows, so
 * no cleanup ran. If this ever fails to apply, duplicates do exist and they must
 * be resolved by hand before retrying -- deliberately not auto-deleted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->unique(['etudiant_id', 'controle_id'], 'notes_etudiant_controle_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropUnique('notes_etudiant_controle_unique');
        });
    }
};