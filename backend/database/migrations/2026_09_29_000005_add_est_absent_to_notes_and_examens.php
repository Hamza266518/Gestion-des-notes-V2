<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->boolean('est_absent')->default(false)->after('valeur');
        });

        Schema::table('examens', function (Blueprint $table) {
            $table->boolean('est_absent')->default(false)->after('valeur');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('est_absent');
        });

        Schema::table('examens', function (Blueprint $table) {
            $table->dropColumn('est_absent');
        });
    }
};
