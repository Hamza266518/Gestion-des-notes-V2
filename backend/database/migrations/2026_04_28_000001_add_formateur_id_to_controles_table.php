<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->foreignId('formateur_id')->nullable()->constrained('users')->onDelete('set null')->after('note_max');
        });
    }

    public function down(): void
    {
        Schema::table('controles', function (Blueprint $table) {
            $table->dropColumn('formateur_id');
        });
    }
};
