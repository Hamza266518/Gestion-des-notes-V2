<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password_encrypted')->comment('Timestamp when user changed password from default');
            $table->string('password_original_encrypted')->nullable()->after('password_changed_at')->comment('Original encrypted password for forgot password verification');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'password_original_encrypted']);
        });
    }
};
