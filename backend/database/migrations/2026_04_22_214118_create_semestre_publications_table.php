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
        Schema::create('semestre_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->onDelete('cascade');
            $table->foreignId('annee_academique_id')->constrained('annees_academiques')->onDelete('cascade');
            $table->integer('semestre');          // 1 or 2
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('semestre_publications');
    }
};
