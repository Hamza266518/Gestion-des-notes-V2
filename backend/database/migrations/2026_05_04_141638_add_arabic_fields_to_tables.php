<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('filieres', function (Blueprint $table) {
            $table->string('nom_ar')->nullable()->after('nom');
            $table->enum('type_formation', ['Qualification', 'Technicien', 'Specialisation'])->nullable()->after('section');
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('nom_ar')->nullable()->after('nom_prenom');
        });
    }

    public function down(): void
    {
        Schema::table('filieres', function (Blueprint $table) {
            $table->dropColumn(['nom_ar', 'type_formation']);
        });

        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn('nom_ar');
        });
    }
};
