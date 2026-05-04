<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Step 1: Add new type column
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->enum('type', ['notes_s1', 'notes_s2', 'bulletin'])->nullable()->after('annee_academique_id');
        });

        // Step 2: Update existing data
        DB::statement("UPDATE semestre_publications SET type = CASE WHEN semestre = 1 THEN 'notes_s1' WHEN semestre = 2 THEN 'notes_s2' ELSE 'notes_s1' END");

        // Step 3: Make type not nullable
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->enum('type', ['notes_s1', 'notes_s2', 'bulletin'])->nullable(false)->change();
        });

        // Step 4: Drop old semestre column
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->dropColumn('semestre');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Step 1: Add back semestre column
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->integer('semestre')->nullable()->after('annee_academique_id');
        });

        // Step 2: Convert type back to semestre
        DB::statement("UPDATE semestre_publications SET semestre = CASE WHEN type = 'notes_s1' THEN 1 WHEN type = 'notes_s2' THEN 2 ELSE 1 END");

        // Step 3: Make semestre not nullable
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->integer('semestre')->nullable(false)->change();
        });

        // Step 4: Drop type column
        Schema::table('semestre_publications', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
