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
        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('date_naissance_ar')->nullable()->after('date_naissance');
            $table->string('lieu_naissance_ar')->nullable()->after('date_naissance_ar');
            $table->string('cin_ar')->nullable()->after('cin');
            $table->string('nationalite_ar')->nullable()->after('nationalite');
            $table->string('numero_inscription_ar')->nullable()->after('numero_inscription');
            $table->string('date_inscription_ar')->nullable()->after('date_inscription');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn([
                'date_naissance_ar',
                'lieu_naissance_ar',
                'cin_ar',
                'nationalite_ar',
                'numero_inscription_ar',
                'date_inscription_ar',
            ]);
        });
    }
};
