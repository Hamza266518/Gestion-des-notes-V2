<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Admin\FormateurController;
use App\Http\Controllers\Admin\AnneeAcademiqueController;
use App\Http\Controllers\Admin\FiliereController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\NiveauController;
use App\Http\Controllers\Admin\GroupeController;
use App\Http\Controllers\Admin\EtudiantController;
use App\Http\Controllers\Admin\UniteController;
use App\Http\Controllers\Admin\SequenceController;
use App\Http\Controllers\Admin\ControleController;
use App\Http\Controllers\Admin\NoteAdminController;
use App\Http\Controllers\Admin\ExamController;
use App\Http\Controllers\Admin\PublicationController;
use App\Http\Controllers\Admin\DiplomeController;
use App\Http\Controllers\Admin\ScanCinController;
use App\Http\Controllers\Admin\ScanUnitesController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ProgressionController;
use App\Http\Controllers\Formateur\ScanController;
use App\Http\Controllers\Formateur\NoteController;
use App\Http\Controllers\Etudiant\PortalController;

// Health check - public
Route::get('/health', [HealthController::class, 'check']);

// Auth - public
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::get('/annee-academique/current', [AnneeAcademiqueController::class, 'current']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // ── ADMIN ──────────────────────────────────────────────
    Route::prefix('admin')->middleware([\App\Http\Middleware\IsAdmin::class, \App\Http\Middleware\RateLimitSensitiveEndpoints::class])->group(function () {

        // Annees academiques
        Route::get('/annees-academiques', [AnneeAcademiqueController::class, 'index']);
        Route::post('/annees-academiques', [AnneeAcademiqueController::class, 'store']);
        Route::post('/annees-academiques/{id}/set-current', [AnneeAcademiqueController::class, 'setCurrent']);
        Route::post('/annees-academiques/{id}/archive', [AnneeAcademiqueController::class, 'archive']);

        // Filieres
        Route::get('/filieres', [FiliereController::class, 'index']);
        Route::post('/filieres', [FiliereController::class, 'store']);
        Route::put('/filieres/{id}', [FiliereController::class, 'update']);
        Route::delete('/filieres/{id}', [FiliereController::class, 'destroy']);

        // Niveaux
        Route::get('/niveaux', [NiveauController::class, 'index']);
        Route::post('/niveaux', [NiveauController::class, 'store']);
        Route::delete('/niveaux/{id}', [NiveauController::class, 'destroy']);

        // Groupes
        Route::get('/groupes', [GroupeController::class, 'index']);
        Route::post('/groupes', [GroupeController::class, 'store']);
        Route::put('/groupes/{id}', [GroupeController::class, 'update']);
        Route::delete('/groupes/{id}', [GroupeController::class, 'destroy']);

        // Etudiants
        Route::get('/etudiants', [EtudiantController::class, 'index']);
        Route::post('/etudiants', [EtudiantController::class, 'store']);
        Route::put('/etudiants/{id}', [EtudiantController::class, 'update']);
        Route::delete('/etudiants/{id}', [EtudiantController::class, 'destroy']);

        // Import Excel etudiants
        Route::post('/import', [ImportController::class, 'import']);
        Route::post('/preview', [ImportController::class, 'preview']);

        // Scan CIN
        Route::post('/scan-cin', [ScanCinController::class, 'scan']);
        Route::post('/scan-cin/confirm', [ScanCinController::class, 'confirm']);
        Route::post('/scan-cin/check-existing', [ScanCinController::class, 'checkExisting']);

        // Formateurs
        Route::get('/formateurs', [FormateurController::class, 'index']);
        Route::post('/formateurs', [FormateurController::class, 'store']);
        Route::delete('/formateurs/{id}', [FormateurController::class, 'destroy']);
        Route::get('/formateurs/{id}/sequences', [FormateurController::class, 'getSequences']);
        Route::post('/formateurs/{id}/assign-sequence', [FormateurController::class, 'assignSequence']);
        Route::delete('/formateurs/{id}/remove-sequence', [FormateurController::class, 'removeSequence']);
        Route::post('/formateurs/{id}/import-sequences', [FormateurController::class, 'importSequences']);
        Route::post('/formateurs/{id}/scan-sequences', [FormateurController::class, 'scanSequences']);
        Route::post('/formateurs/import', [FormateurController::class, 'import']);
        Route::post('/formateurs/preview', [FormateurController::class, 'preview']);
        Route::post('/formateurs/{id}/update-password', [FormateurController::class, 'updatePassword']);

        // Unites
        Route::get('/unites', [UniteController::class, 'index']);
        Route::post('/unites', [UniteController::class, 'store']);
        Route::put('/unites/{id}', [UniteController::class, 'update']);
        Route::delete('/unites/{id}', [UniteController::class, 'destroy']);
        Route::post('/unites/{id}/toggle-active', [UniteController::class, 'toggleActive']);
        Route::post('/unites/import', [UniteController::class, 'import']);
        Route::post('/unites/preview', [UniteController::class, 'preview']);

        // Scan Unites Document
        Route::post('/scan-unites', [ScanUnitesController::class, 'scan']);
        Route::post('/scan-unites/confirm', [ScanUnitesController::class, 'confirm']);

        // Sequences
        Route::get('/sequences', [SequenceController::class, 'index']);
        Route::post('/sequences', [SequenceController::class, 'store']);
        Route::put('/sequences/{id}', [SequenceController::class, 'update']);
        Route::delete('/sequences/{id}', [SequenceController::class, 'destroy']);
        Route::post('/sequences/{id}/toggle-active', [SequenceController::class, 'toggleActive']);

        // Controles
        Route::get('/controles', [ControleController::class, 'index']);
        Route::post('/controles', [ControleController::class, 'store']);
        Route::put('/controles/{id}/rename', [SequenceController::class, 'renameControle']);
        Route::put('/controles/{id}/type', [SequenceController::class, 'setControleType']);
        Route::delete('/controles/{id}', [ControleController::class, 'destroy']);
        Route::post('/controles/generate/{sequenceId}', [ControleController::class, 'generateForSequence']);

        // Notes admin
        Route::get('/notes', [NoteAdminController::class, 'index']);
        Route::put('/notes/{id}', [NoteAdminController::class, 'update']);
        Route::get('/recap-notes', [NoteAdminController::class, 'recapNotes']);
        Route::get('/bulletins', [NoteAdminController::class, 'bulletin']);
        Route::get('/examens', [ExamController::class, 'index']);
        Route::post('/examens/bulk', [ExamController::class, 'bulkStore']);

        // Publications
        Route::get('/publications', [PublicationController::class, 'index']);
        Route::post('/publications/publish', [PublicationController::class, 'publish']);
        Route::post('/publications/publish-all', [PublicationController::class, 'publishAll']);
        Route::post('/publications/unpublish', [PublicationController::class, 'unpublish']);

        // Diplomes
        Route::get('/diplomes', [DiplomeController::class, 'index']);
        Route::post('/diplomes/generate', [DiplomeController::class, 'generate']);
        Route::post('/diplomes/generate-all', [DiplomeController::class, 'generateAll']);
        Route::put('/diplomes/{id}/printed', [DiplomeController::class, 'markPrinted']);
        Route::get('/diplomes/{id}/download', [DiplomeController::class, 'download']);

        // Activity logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        // Progression
        Route::get('/progression/check-redoublants', [ProgressionController::class, 'checkRedoublants']);
        Route::post('/progression/confirm-redoublants', [ProgressionController::class, 'confirmRedoublants']);
        Route::post('/progression/drop-redoublants', [ProgressionController::class, 'dropRedoublants']);
        Route::post('/progression/promote-admis', [ProgressionController::class, 'promoteAdmis']);
        Route::post('/progression/copy-groups', [ProgressionController::class, 'copyGroups']);
    });

    // ── FORMATEUR ──────────────────────────────────────────
    Route::prefix('formateur')->middleware(\App\Http\Middleware\IsFormateur::class)->group(function () {
        Route::post('/scan', [ScanController::class, 'scan']);
        Route::post('/confirm', [ScanController::class, 'confirm']);
        Route::get('/notes', [NoteController::class, 'index']);
        Route::put('/notes/{id}', [NoteController::class, 'update']);
        Route::get('/sequences', [NoteController::class, 'mySequences']);
        Route::get('/scan-data', [NoteController::class, 'scanData']);
    });

    // ── ETUDIANT ───────────────────────────────────────────
    Route::prefix('etudiant')->middleware(\App\Http\Middleware\IsEtudiant::class)->group(function () {
        Route::get('/bulletin', [PortalController::class, 'monBulletin']);
    });
});