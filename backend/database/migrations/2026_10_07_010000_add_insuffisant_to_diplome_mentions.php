<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Manual diploma generation (generateManual) skips the moyenne ≥ 10 gate, so
     * 'Insuffisant' is now a reachable mention. The enum only allowed the four
     * pass mentions because the old flow could never write a failing grade.
     */
    public function up(): void
    {
        Schema::table('diplomes', function (Blueprint $table) {
            $table->enum('mention', ['Insuffisant', 'Passable', 'Assez Bien', 'Bien', 'Très Bien'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('diplomes', function (Blueprint $table) {
            $table->enum('mention', ['Passable', 'Assez Bien', 'Bien', 'Très Bien'])->change();
        });
    }
};