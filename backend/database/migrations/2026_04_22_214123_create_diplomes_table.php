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
        Schema::create('diplomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->onDelete('cascade');
            $table->foreignId('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
            $table->float('moyenne_generale');
            $table->enum('mention', ['Passable', 'Assez Bien', 'Bien', 'Très Bien']);
            $table->boolean('is_printed')->default(false);
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diplomes');
    }
};
