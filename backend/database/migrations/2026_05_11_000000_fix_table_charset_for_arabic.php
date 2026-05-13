<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER DATABASE `{$this->getDatabaseName()}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $tables = ['filieres', 'etudiants', 'groupes', 'niveaux', 'unites', 'sequences', 'controles', 'notes', 'diplomes'];
        foreach ($tables as $table) {
            DB::statement("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
    }

    public function down(): void
    {
    }

    private function getDatabaseName(): string
    {
        return config('database.connections.mysql.database');
    }
};
