<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One per-service "Examen" mark per student.
 *
 * The Stage units' controls are the services and each of them already carries a
 * note (its Évaluation / service mark) in `notes` — one row per
 * (student, control), enforced by the notes_etudiant_controle_unique index that
 * every read path relies on. The official Examen grille adds a second mark per
 * service, so it cannot live in `notes`; this table stores it, unique per
 * (student, service, type).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('etudiant_id')->constrained('etudiants')->cascadeOnDelete();
            $table->foreignId('controle_id')->constrained('controles')->cascadeOnDelete();
            $table->string('type')->default('grille_examen');
            $table->float('valeur');
            $table->boolean('est_absent')->default(false);
            $table->timestamps();

            $table->unique(['etudiant_id', 'controle_id', 'type'], 'stage_notes_etudiant_controle_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_notes');
    }
};