<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'filieres'  => ['nom_ar', 'nom'],
            'etudiants' => ['nom_ar'],
            'unites'    => ['nom'],
            'sequences' => ['nom'],
        ];

        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
            }
        }
    }

    public function down(): void
    {
    }
};
