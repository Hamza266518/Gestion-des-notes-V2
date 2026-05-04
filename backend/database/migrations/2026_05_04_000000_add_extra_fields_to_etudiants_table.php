<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->string('lieu_naissance')->nullable()->after('date_naissance');
            $table->string('nationalite')->default('Marocaine')->after('lieu_naissance');
            $table->date('date_inscription')->nullable()->after('nationalite');
        });
    }
    public function down(): void {
        Schema::table('etudiants', function (Blueprint $table) {
            $table->dropColumn(['lieu_naissance', 'nationalite', 'date_inscription']);
        });
    }
};
