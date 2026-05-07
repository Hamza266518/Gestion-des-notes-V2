<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->enum('type', ['cc', 'theorique', 'pratique'])->default('cc')->after('numero');
        });
    }

    public function down(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
