<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One publication row per (groupe, année, type) is the invariant the lock assumes.
 *
 * PublicationController::publish and ::publishAll both resolve the row with
 * `updateOrCreate`, which is a SELECT followed by an INSERT. Two admins clicking
 * publish at the same moment, or one request retried by a proxy, both see "no row
 * yet" and both insert.
 *
 * Duplicates are not cosmetic. `PublicationLockService::verrouilleTout()` counts
 * locked groups against the groups in range, so a group returned twice by the
 * query makes that comparison fail and the control reads as *not* published --
 * grades editable on a transcript the school already released. That is exactly
 * the failure a `distinct()` had to paper over in groupesVerrouilles().
 *
 * The index makes the race a loud failure instead of silent corruption.
 *
 * Verified before writing this migration: 0 duplicate keys in both databases
 * (21 rows in dev, 10 in test), so no cleanup ran. Should this ever fail to
 * apply, duplicates do exist and must be resolved by hand -- deliberately not
 * auto-deleted here, because two rows for one key can disagree on is_published
 * and collapsing them silently would change what is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->unique(
                ['groupe_id', 'annee_academique_id', 'type'],
                'semestre_publications_groupe_annee_type_unique'
            );
        });
    }

    public function down(): void
    {
        // The foreign keys are dropped first on purpose. MySQL satisfies the
        // groupe_id constraint using the leftmost column of the new unique
        // index, so dropping that index while the key still references it fails
        // with errno 1553. Dropping both keys, removing the index, then
        // rebuilding the keys with constrained() restores the exact index names
        // the create migration produced, so up() and down() round-trip cleanly.
        //
        // The columns are passed as arrays on purpose: dropForeign() only derives
        // the constraint name from an array. Given a bare string it uses that
        // string verbatim as the key name, which produces `drop foreign key
        // groupe_id` and fails with errno 1091.
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->dropForeign(['groupe_id']);
            $table->dropForeign(['annee_academique_id']);
            $table->dropUnique('semestre_publications_groupe_annee_type_unique');
        });

        // foreign(), not foreignId()->constrained(). The latter also declares the
        // column, and the column is still there, so it fails with errno 1060
        // "Duplicate column name". foreign() restores only the constraint;
        // MySQL re-creates the supporting index under the constraint name, which
        // is the name the create migration produced.
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->foreign('groupe_id')->references('id')->on('groupes')->onDelete('cascade');
            $table->foreign('annee_academique_id')->references('id')->on('annees_academiques')->onDelete('cascade');
        });
    }
};
