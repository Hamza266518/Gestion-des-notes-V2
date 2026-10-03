<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the scan-name cache per formateur.
 *
 * The cache exists so the second time a sheet is scanned the same name resolves
 * instantly. But it was keyed globally on (raw_nom, raw_nom_ar) while the row
 * also stores a formateur_id that nothing ever filtered on, so two formateurs
 * teaching in the same school shared one cache entry:
 *
 *   - the write used updateOrCreate on the name alone, so formateur A re-pointing
 *     a name overwrote whatever formateur B had recorded for it;
 *   - the read looked the name up with no formateur filter at all.
 *
 * `gemini_nom` originates from the AI reading a sheet, and the confirm endpoint
 * accepts it straight from the client, so one formateur could steer a name other
 * formateurs scan toward a student of their choosing, and the next scan would
 * file that grade against the wrong person.
 *
 * Both halves now filter on formateur_id, and the uniqueness constraint follows
 * so each formateur gets an independent entry. Verified before writing: all 35
 * existing rows already carry a formateur_id, so nothing needs backfilling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_name_cache', function (Blueprint $table) {
            $table->dropUnique(['raw_nom', 'raw_nom_ar']);
            $table->unique(
                ['formateur_id', 'raw_nom', 'raw_nom_ar'],
                'scan_name_cache_formateur_nom_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('scan_name_cache', function (Blueprint $table) {
            $table->dropUnique('scan_name_cache_formateur_nom_unique');
            // Only safe because no two formateurs record the same name today;
            // the caller is responsible for collapsing duplicates first.
            $table->unique(['raw_nom', 'raw_nom_ar']);
        });
    }
};