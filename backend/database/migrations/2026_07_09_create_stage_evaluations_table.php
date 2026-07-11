<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('annee_academique_id')->constrained('annees_academiques')->cascadeOnDelete();
            $table->string('type'); // garde_nuit, grille_evaluation, grille_examen
            $table->json('data');
            $table->timestamps();

            $table->unique(['etudiant_id', 'annee_academique_id', 'type'], 'stage_eval_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_evaluations');
    }
};
