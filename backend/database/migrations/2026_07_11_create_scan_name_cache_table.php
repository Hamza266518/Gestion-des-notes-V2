<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_name_cache', function (Blueprint $table) {
            $table->id();
            $table->string('raw_nom', 255);
            $table->string('raw_nom_ar', 255)->default('');
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->unsignedBigInteger('formateur_id')->nullable();
            $table->timestamps();

            $table->unique(['raw_nom', 'raw_nom_ar']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_name_cache');
    }
};
