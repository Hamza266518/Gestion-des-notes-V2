<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\AccountController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Admin\FormateurController;
use App\Http\Controllers\Admin\AnneeAcademiqueController;
use App\Http\Controllers\Admin\FiliereController;
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
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\StageController;
use App\Http\Controllers\Formateur\ScanController;
use App\Http\Controllers\Formateur\NoteController;
use App\Http\Controllers\Etudiant\PortalController;

// Health check - public
Route::get('/health', [HealthController::class, 'check']);

// Auth - public
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
Route::get('/annee-academique/current', [AnneeAcademiqueController::class, 'current']);

// Password recovery (unauthenticated, every role): username -> code sent to the verified contact email
Route::post('/auth/password/forgot', [PasswordRecoveryController::class, 'sendCode'])
    ->middleware('throttle:6,1');
Route::post('/auth/password/reset', [PasswordRecoveryController::class, 'reset'])
    ->middleware('throttle:5,1');

// Batch .rar download: the frontend navigates a plain <a> to this URL, so the
// admin token is passed in the query and checked manually in the controller.
Route::get('/admin/diplomes/batch-download', [DiplomeController::class, 'batchDownload']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Mon compte (every role)
    Route::get('/account', [AccountController::class, 'show']);
    Route::put('/account/username', [AccountController::class, 'changeUsername']);
    Route::put('/account/email', [AccountController::class, 'updateEmail'])->middleware('throttle:6,1');
    Route::post('/account/email/verify', [AccountController::class, 'verifyEmail'])->middleware('throttle:8,1');
    Route::post('/account/email/resend', [AccountController::class, 'resendEmailVerification'])->middleware('throttle:3,1');
    Route::delete('/account/email', [AccountController::class, 'removeEmail']);
    Route::post('/account/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1');

    // ── ADMIN ──────────────────────────────────────────────
    Route::prefix('admin')->middleware([\App\Http\Middleware\IsAdmin::class, \App\Http\Middleware\RateLimitSensitiveEndpoints::class])->group(function () {

        // Annees academiques
        Route::get('/annees-academiques', [AnneeAcademiqueController::class, 'index']);
        Route::post('/annees-academiques', [AnneeAcademiqueController::class, 'store']);
        Route::post('/annees-academiques/{id}/set-current', [AnneeAcademiqueController::class, 'setCurrent']);
Route::post('/annees-academiques/{id}/archive', [AnneeAcademiqueController::class, 'archive']);
Route::post('/annees-academiques/{id}/unarchive', [AnneeAcademiqueController::class, 'unarchive']);
Route::put('/annees-academiques/{id}', [AnneeAcademiqueController::class, 'update']);
Route::get('/annees-academiques/{id}/stats', [AnneeAcademiqueController::class, 'stats']);

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
        Route::get('/etudiants/docx/liste', [EtudiantController::class, 'docxListe']);
        Route::get('/etudiants/docx/identifiants', [EtudiantController::class, 'docxIdentifiants']);
        Route::post('/etudiants', [EtudiantController::class, 'store']);
        Route::put('/etudiants/{id}', [EtudiantController::class, 'update']);
        Route::delete('/etudiants/{id}', [EtudiantController::class, 'destroy']);
        Route::post('/translate-lieu', [EtudiantController::class, 'translateLieu']);

        // Scan CIN
        Route::post('/scan-cin', [ScanCinController::class, 'scan']);
        Route::post('/scan-cin/confirm', [ScanCinController::class, 'confirm']);
        Route::post('/scan-cin/apply', [ScanCinController::class, 'apply']);
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
        Route::delete('/controles/{id}', [ControleController::class, 'destroy']);
        Route::post('/controles/generate/{sequenceId}', [ControleController::class, 'generateForSequence']);

        // Notes admin
Route::get('/notes', [NoteAdminController::class, 'index']);
Route::post('/notes', [NoteAdminController::class, 'store']);
Route::put('/notes/{id}', [NoteAdminController::class, 'update']);
        Route::get('/recap-notes', [NoteAdminController::class, 'recapNotes']);
        Route::get('/recap-notes/table-types', [NoteAdminController::class, 'recapTableTypes']);
        Route::get('/recap-notes/docx', [NoteAdminController::class, 'recapDocx']);
        Route::get('/recap-notes/docx-all', [NoteAdminController::class, 'recapDocxAll']);
        Route::get('/bulletins', [NoteAdminController::class, 'bulletin']);
        Route::get('/bulletin/docx', [NoteAdminController::class, 'bulletinDocx']);
        Route::get('/bulletin/docx-all', [NoteAdminController::class, 'bulletinDocxAll']);
        Route::get('/examens', [ExamController::class, 'index']);
        Route::post('/examens/bulk', [ExamController::class, 'bulkStore']);

        // Publications
        Route::get('/publications', [PublicationController::class, 'index']);
        Route::post('/publications/publish', [PublicationController::class, 'publish']);
        Route::post('/publications/publish-all', [PublicationController::class, 'publishAll']);
        Route::post('/publications/unpublish', [PublicationController::class, 'unpublish']);
        Route::post('/publications/unpublish-all', [PublicationController::class, 'unpublishAll']);

        // Diplomes
        Route::get('/diplomes', [DiplomeController::class, 'index']);
        Route::get('/diplomes/templates-meta', [DiplomeController::class, 'templatesMeta']);
        Route::get('/diplomes/informations', [DiplomeController::class, 'informations']);
        Route::put('/diplomes/informations', [DiplomeController::class, 'saveInformations']);
        Route::put('/diplomes/informations/positions', [DiplomeController::class, 'saveInformationsPositions']);
        Route::get('/diplomes/manual-candidates', [DiplomeController::class, 'manualCandidates']);
        Route::get('/diplomes/check-readiness', [DiplomeController::class, 'checkReadiness']);
        Route::post('/diplomes/generate', [DiplomeController::class, 'generate']);
        Route::post('/diplomes/manual-generate', [DiplomeController::class, 'generateManual']);
        Route::post('/diplomes/batch-generate', [DiplomeController::class, 'batchGenerate']);
        Route::post('/diplomes/batch-archive', [DiplomeController::class, 'batchArchive']);
        Route::post('/diplomes/generate-all', [DiplomeController::class, 'generateAll']);
        Route::put('/diplomes/{id}/printed', [DiplomeController::class, 'markPrinted']);
        Route::get('/diplomes/{id}/download', [DiplomeController::class, 'download']);

        // Scan examens
        Route::post('/scan-examens', [\App\Http\Controllers\Admin\ScanExamenController::class, 'scan']);
        Route::post('/scan-examens/confirm', [\App\Http\Controllers\Admin\ScanExamenController::class, 'confirm']);
        Route::get('/scan-examens/search-etudiants', [\App\Http\Controllers\Admin\ScanExamenController::class, 'searchEtudiants']);

        // Stage evaluations
        Route::get('/stage/search-students', [StageController::class, 'searchStudents']);
        Route::get('/stage/services', [StageController::class, 'stageServices']);
        Route::post('/stage/save', [StageController::class, 'save']);
        Route::get('/stage/load', [StageController::class, 'load']);
        Route::get('/stage/notes', [StageController::class, 'notes']);
        Route::post('/stage/notes', [StageController::class, 'saveNote']);

        // Soutenance (NSTI): the jury criteria are Note rows on the Soutenance
        // unit's controls, scanned from the jury sheet.
        Route::get('/soutenance/programmes', [\App\Http\Controllers\Admin\SoutenanceController::class, 'programmes']);
        Route::get('/soutenance/status', [\App\Http\Controllers\Admin\SoutenanceController::class, 'status']);
        Route::post('/soutenance/scan', [\App\Http\Controllers\Admin\SoutenanceController::class, 'scan']);
        Route::post('/soutenance/confirm', [\App\Http\Controllers\Admin\SoutenanceController::class, 'confirm']);

        // Activity logs
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);

        // Dashboard stats
        Route::get('/dashboard-stats', [DashboardController::class, 'stats']);
        Route::get('/controles-pending', [DashboardController::class, 'controlesPending']);

        // Progression
        Route::get('/progression/check-redoublants', [ProgressionController::class, 'checkRedoublants']);
        Route::post('/progression/confirm-redoublants', [ProgressionController::class, 'confirmRedoublants']);
        Route::post('/progression/drop-redoublants', [ProgressionController::class, 'dropRedoublants']);
        Route::post('/progression/promote-admis', [ProgressionController::class, 'promoteAdmis']);
        Route::post('/progression/copy-groups', [ProgressionController::class, 'copyGroups']);
    });

    // ── FORMATEUR ──────────────────────────────────────────
    Route::prefix('formateur')->middleware([\App\Http\Middleware\IsFormateur::class, \App\Http\Middleware\RateLimitSensitiveEndpoints::class])->group(function () {
        Route::post('/scan', [ScanController::class, 'scan']);
        Route::post('/confirm', [ScanController::class, 'confirm']);
        Route::get('/notes', [NoteController::class, 'index']);
        Route::put('/notes/{id}', [NoteController::class, 'update']);
        Route::get('/sequences', [NoteController::class, 'mySequences']);
        Route::get('/scan-data', [NoteController::class, 'scanData']);
        Route::get('/etudiants', [NoteController::class, 'searchEtudiants']);
        Route::get('/sequences/{id}/etudiants', [NoteController::class, 'sequenceEtudiants']);
        Route::get('/groupes', [NoteController::class, 'groupes']);
        Route::get('/publication-status', [NoteController::class, 'publicationStatus']);
    });

    // ── ETUDIANT ───────────────────────────────────────────
    Route::prefix('etudiant')->middleware(\App\Http\Middleware\IsEtudiant::class)->group(function () {
        Route::get('/bulletin', [PortalController::class, 'monBulletin']);

        // First-login password status (the change itself lives on /account/password)
        Route::get('/password/check-first-login', [\App\Http\Controllers\Etudiant\PasswordController::class, 'checkFirstLogin']);
    });
});