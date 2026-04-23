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
        Schema::create('unites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('filiere_id')->constrained('filieres')->onDelete('cascade');
            $table->string('nom');
            $table->integer('coefficient');
            $table->integer('numero_annee');      // 1, 2, 3
            $table->integer('semestre');          // 1, 2
            $table->integer('ordre')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unites');
    }
};
