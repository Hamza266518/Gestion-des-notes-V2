<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('examens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->onDelete('cascade');
            $table->foreignId('unite_id')->constrained('unites')->onDelete('cascade');
            $table->integer('bloc'); // 1 or 2
            $table->string('type'); // theorique or pratique
            $table->integer('semestre'); // 1 or 2
            $table->foreignId('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
            $table->float('valeur')->nullable();
            $table->timestamps();

            $table->unique(['etudiant_id', 'unite_id', 'bloc', 'type', 'semestre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('examens');
    }
};
