<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants');
        });

        Schema::table('examens', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants');
        });

        Schema::table('diplomes', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants')->onDelete('cascade');
        });

        Schema::table('examens', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants')->onDelete('cascade');
        });

        Schema::table('diplomes', function (Blueprint $table) {
            $table->dropForeign(['etudiant_id']);
            $table->foreign('etudiant_id')->references('id')->on('etudiants')->onDelete('cascade');
        });
    }
};
