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
        Schema::create('formateur_unites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formateur_id')->constrained('formateurs')->onDelete('cascade');
            $table->foreignId('unite_id')->constrained('unites')->onDelete('cascade');
            $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
