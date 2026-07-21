<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_name_cache', function (Blueprint $table) {
            $table->string('raw_nom_normalized', 255)->after('raw_nom_ar')->index();
        });
    }

    public function down(): void
    {
        Schema::table('scan_name_cache', function (Blueprint $table) {
            $table->dropColumn('raw_nom_normalized');
        });
    }
};
