<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        $db = DB::getDatabaseName();

        // --- Fix etudiants.annee_academique_id FK: cascade → restrict ---
        if ($this->fkExists($db, 'etudiants', 'annee_academique_id')) {
            Schema::table('etudiants', function (Blueprint $table) {
                $table->dropForeign(['annee_academique_id']);
            });
        }
        Schema::table('etudiants', function (Blueprint $table) {
            $table->foreign('annee_academique_id')
                  ->references('id')
                  ->on('annees_academiques')
                  ->onDelete('restrict');
        });

        // --- Fix groupes.annee_academique_id FK: cascade → restrict ---
        if ($this->fkExists($db, 'groupes', 'annee_academique_id')) {
            Schema::table('groupes', function (Blueprint $table) {
                $table->dropForeign(['annee_academique_id']);
            });
        }
        Schema::table('groupes', function (Blueprint $table) {
            $table->foreign('annee_academique_id')
                  ->references('id')
                  ->on('annees_academiques')
                  ->onDelete('restrict');
        });

        // --- Make groupe_id nullable first (required for set null FK) ---
        Schema::table('etudiants', function (Blueprint $table) {
            $table->foreignId('groupe_id')->nullable()->change();
        });

        // --- Fix etudiants.groupe_id FK: cascade → set null ---
        if ($this->fkExists($db, 'etudiants', 'groupe_id')) {
            Schema::table('etudiants', function (Blueprint $table) {
                $table->dropForeign(['groupe_id']);
            });
        }
        Schema::table('etudiants', function (Blueprint $table) {
            $table->foreign('groupe_id')
                  ->references('id')
                  ->on('groupes')
                  ->onDelete('set null');
        });

        // --- Add status column to etudiants ---
        if (!Schema::hasColumn('etudiants', 'status')) {
            Schema::table('etudiants', function (Blueprint $table) {
                $table->string('status')->default('active')->after('groupe_id');
            });
        }
    }

    public function down(): void {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropForeign(['groupe_id']);
            $table->foreignId('groupe_id')->nullable(false)->change();
            $table->foreign('groupe_id')->constrained('groupes')->onDelete('cascade');
        });

        Schema::table('groupes', function (Blueprint $table) {
            $table->dropForeign(['annee_academique_id']);
            $table->foreign('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropForeign(['annee_academique_id']);
            $table->foreign('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
        });
    }

    private function fkExists(string $db, string $table, string $column): bool
    {
        $result = DB::select(
            'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?
             AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$db, $table, $column]
        );
        return count($result) > 0;
    }
};
