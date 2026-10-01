<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curriculum_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('cible_type');            // unites | sequences
            $table->unsignedBigInteger('cible_id');
            $table->string('champ');                 // coefficient | nombre_controles | ordre
            $table->string('ancienne_valeur')->nullable();
            $table->string('nouvelle_valeur')->nullable();
            $table->string('source');                 // document de reference
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['cible_type', 'cible_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_corrections');
    }
};
