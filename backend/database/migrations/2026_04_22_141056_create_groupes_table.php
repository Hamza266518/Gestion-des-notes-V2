<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('groupes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('niveau_id')->constrained('niveaux')->onDelete('cascade');
            $table->foreignId('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
            $table->string('nom');                // Groupe A
            $table->string('promotion');          // 2025/2026
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groupes');
    }
};
