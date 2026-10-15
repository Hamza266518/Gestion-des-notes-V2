<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Establishment-level values printed inside the diploma's pre-typed
     * certification text (the blanks that are identical for every student:
     * opening-authorisation date, accreditation number/date, visa number/date,
     * serial number). `positions` stores the calibrated marker coordinates per
     * template type, so the admin places each value from the UI instead of
     * editing config/diplomes.php.
     */
    public function up(): void
    {
        Schema::create('diplome_parametres', function (Blueprint $table) {
            $table->id();
            $table->json('valeurs')->nullable();
            $table->json('positions')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diplome_parametres');
    }
};
