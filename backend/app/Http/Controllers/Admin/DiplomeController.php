<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Models\Controle;
use App\Models\Examen;
use App\Models\Groupe;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\DiplomaDocxService;
use App\Services\MoyenneService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\PersonalAccessToken;

class DiplomeController extends Controller
{
    protected $moyenneService;
    protected $bulletinService;

    public function __construct(MoyenneService $moyenneService, BulletinService $bulletinService)
    {
        $this->moyenneService = $moyenneService;
        $this->bulletinService = $bulletinService;
    }

    /**
     * The group this student sat in for `$anneeId`, not necessarily the one they are
     * enrolled in now. Mirrors BulletinService::resolveGroupe: promotion rewrites
     * etudiants.annee_academique_id, so asking a promoted student's cohort about a
     * publication on their current group would never match.
     */
    private function groupePourAnnee(Etudiant $etudiant, int $anneeId): ?Groupe
    {
        $groupe = $etudiant->groupe;
        if (!$groupe) return null;

        if ((int) $groupe->annee_academique_id === $anneeId) {
            return $groupe;
        }

        return Groupe::where('annee_academique_id', $anneeId)
            ->where('niveau_id', $groupe->niveau_id)
            ->first();
    }

    private function checkBulletinPublished($etudiant, $anneeAcademiqueId)
    {
        $groupe = $this->groupePourAnnee($etudiant, (int) $anneeAcademiqueId);
        if (!$groupe) return false;

        return SemestrePublication::where('groupe_id', $groupe->id)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->where('type', 'bulletin')
            ->where('is_published', true)
            ->exists();
    }

    /**
     * True when nothing is missing that BulletinService would need.
     *
     * A mark counts as filled when it exists with a valeur (which includes
     * `est_absent`: NoteParserService stores absence as 0) or when the unit's exam
     * grade for this student is present. Exam rows were previously ignored here
     * while BulletinService folds them into the same averages, so readiness could
     * pass over a transcript with half its theory and practical marks absent.
     */
    private function checkAllNotesFilled($etudiant, $anneeAcademiqueId)
    {
        $groupe = $this->groupePourAnnee($etudiant, (int) $anneeAcademiqueId);
        $niveau = $groupe?->niveau;
        if (!$niveau) return false;

        $filiereId = $niveau->filiere_id;

        $uniteIds = Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $niveau->numero)
            ->where('is_active', true)
            ->pluck('id');

        // No units means no curriculum to fill, so nothing is missing. Returning true
        // here rather than false keeps the "all filled" reading honest: there is
        // nothing outstanding. The caller separately requires a published bulletin,
        // which an empty curriculum cannot satisfy.
        if ($uniteIds->isEmpty()) return true;

        $unitsFromNotes = Note::where('etudiant_id', $etudiant->id)
            ->whereNotNull('valeur')
            ->join('controles', 'controles.id', '=', 'notes.controle_id')
            ->leftJoin('sequences', 'sequences.id', '=', 'controles.sequence_id')
            ->where(function ($q) use ($uniteIds) {
                $q->whereIn('sequences.unite_id', $uniteIds)
                    ->orWhere(function ($q) use ($uniteIds) {
                        $q->whereNull('controles.sequence_id')->whereIn('controles.unite_id', $uniteIds);
                    });
            })
            ->distinct()
            ->pluck(DB::raw('COALESCE(sequences.unite_id, controles.unite_id) as unite_id'));

        $unitsFromExamens = Examen::where('etudiant_id', $etudiant->id)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->whereNotNull('valeur')
            ->whereIn('unite_id', $uniteIds)
            ->distinct()
            ->pluck('unite_id');

        // Union, not a sum: a unit graded by both a control and an exam must only
        // count once, or the total could exceed the unit count and pass by accident.
        $filled = $unitsFromNotes->merge($unitsFromExamens)->unique()->count();

        return $filled >= $uniteIds->count();
    }

    public function index(Request $request)
    {
        try {
            $diplomes = Diplome::with([
                'etudiant.groupe.niveau.filiere',
                'anneeAcademique'
            ])
            ->whereHas('etudiant', fn($q) => $q->where('status', 'graduate'))
            ->when($request->annee_academique_id, fn($q) =>
                $q->where('annee_academique_id', $request->annee_academique_id)
            )
            ->get();

            return response()->json(['success' => true, 'data' => $diplomes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function generate(Request $request)
    {
        try {
            $request->validate([
                'etudiant_id'         => 'required|exists:etudiants,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'semestre'            => 'required|integer|in:1,2',
            ]);

            $etudiant = Etudiant::with('groupe.niveau.filiere')->findOrFail($request->etudiant_id);
            $niveau = $etudiant->groupe?->niveau;
            $filiere = $niveau?->filiere;

            if (!$niveau || !$filiere || $niveau->numero !== $filiere->nombre_annees) {
                return response()->json(['success' => false, 'message' => 'Seuls les étudiants en année diplômante peuvent obtenir un diplôme'], 400);
            }

            if (!$this->checkBulletinPublished($etudiant, $request->annee_academique_id)) {
                return response()->json(['success' => false, 'message' => 'Le bulletin doit être publié avant de générer le diplôme'], 400);
            }

            if (!$this->checkAllNotesFilled($etudiant, $request->annee_academique_id)) {
                return response()->json(['success' => false, 'message' => 'Toutes les notes de l\'étudiant doivent être saisies avant de générer le diplôme'], 400);
            }

            $bulletin = $this->bulletinService->calculateBulletin(
                $request->etudiant_id,
                $request->annee_academique_id
            );

            $moyenne = $bulletin['moyenne_generale'];

            if (!$moyenne) {
                return response()->json(['success' => false, 'message' => 'Notes incomplètes'], 400);
            }

            if ($moyenne < 10) {
                return response()->json(['success' => false, 'message' => 'Moyenne insuffisante (' . $moyenne . '/20)'], 400);
            }

            $diplome = Diplome::updateOrCreate(
                ['etudiant_id' => $request->etudiant_id, 'annee_academique_id' => $request->annee_academique_id],
                ['moyenne_generale' => $moyenne, 'mention' => $this->bulletinService->getMention($moyenne)['label']]
            );

            $etudiant->update(['status' => 'graduate']);

            return response()->json(['success' => true, 'data' => $diplome, 'message' => 'Diplôme généré']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération du diplôme'], 500);
        }
    }

    /**
     * Students the admin can hand a diploma to. Every final-year student of the
     * filière (année diplômante) is listed, whether or not a diploma was already
     * recorded: generating again overwrites it (updateOrCreate). Earlier years
     * can never earn a diploma, so they must not show up here.
     * `annee_diplomante` is always true for the rows returned.
     */
    public function manualCandidates(Request $request)
    {
        $request->validate([
            'annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        try {

            $anneeId = (int) $request->annee_academique_id;

            $isAnneeDiplomante = fn($e) => $e->groupe?->niveau?->filiere
                && $e->groupe->niveau->numero === $e->groupe->niveau->filiere->nombre_annees;

            $candidates = Etudiant::with(['groupe.niveau.filiere'])
                ->where('annee_academique_id', $anneeId)
                ->get()
                ->filter($isAnneeDiplomante)
                ->map(fn($e) => [
                    'id'               => $e->id,
                    'nom_prenom'       => $e->nom_prenom,
                    'cin'              => $e->cin,
                    'filiere'          => $e->groupe?->niveau?->filiere?->nom ?? '',
                    'type_formation'   => $e->groupe?->niveau?->filiere?->type_formation ?? '',
                    'groupe'           => $e->groupe?->nom ?? '',
                    'annee_diplomante' => true,
                    'niveau_numero'    => $e->groupe?->niveau?->numero,
                    'nombre_annees'    => $e->groupe?->niveau?->filiere?->nombre_annees,
                ])
                ->sortBy('nom_prenom')
                ->values();

            return response()->json(['success' => true, 'data' => $candidates]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Forge a diploma without waiting for every note or a published bulletin.
     *
     * The normal gates (bulletin publié, toutes notes saisies, moyenne ≥ 10,
     * année diplômante) are deliberately skipped: this is the admin's manual
     * override for a student the school decides to graduate anyway.
     */
    public function generateManual(Request $request)
    {
        $request->validate([
            'etudiant_id'         => 'required|exists:etudiants,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        try {

            $etudiant = Etudiant::with('groupe.niveau.filiere')->findOrFail($request->etudiant_id);
            $diplome = $this->forgeDiplome($etudiant, (int) $request->annee_academique_id);

            return response()->json(['success' => true, 'data' => $diplome, 'message' => 'Diplôme généré manuellement']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération manuelle du diplôme'], 500);
        }
    }

    /**
     * The override itself, shared by generateManual(): forge the diploma row
     * for one student, skipping every gate -- graduating year, notes, bulletin.
     */
    private function forgeDiplome(Etudiant $etudiant, int $anneeId): Diplome
    {
        // Partial notes are fine here: whatever the bulletin can compute is
        // what the diploma records (0 when nothing can be computed).
        $bulletin = $this->bulletinService->calculateBulletin($etudiant->id, $anneeId);
        $moyenne = (float) ($bulletin['moyenne_generale'] ?? 0);

        $diplome = Diplome::updateOrCreate(
            ['etudiant_id' => $etudiant->id, 'annee_academique_id' => $anneeId],
            ['moyenne_generale' => $moyenne, 'mention' => $this->bulletinService->getMention($moyenne)['label']]
        );

        $etudiant->update(['status' => 'graduate']);

        return $diplome;
    }

    /**
     * One step of the "générer toute la liste" run: build the .docx for one
     * student and park it in storage/app/diploma_batch/{batch}/{filiere}/.
     * Files only: this run never writes to the database. No rules either -- the
     * frontend calls it once per student in the manual list, so its progress
     * bar counts requests that actually came back, never a timer.
     */
    public function batchGenerate(Request $request)
    {
        // Each .docx embeds a full-page template image, so one request can take
        // a few seconds; never let the default 30s max_execution_time cut it off.
        set_time_limit(120);

        $request->validate([
            'etudiant_id'         => 'required|exists:etudiants,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'batch'               => 'required|string|regex:/^[A-Za-z0-9_-]{1,64}$/',
        ]);

        try {
            $etudiant = Etudiant::with('groupe.niveau.filiere')->findOrFail($request->etudiant_id);

            // DiplomaDocxService only reads the student (never the diplome row),
            // so a transient instance is enough -- nothing is persisted.
            $diplome = new Diplome([
                'etudiant_id'         => $etudiant->id,
                'annee_academique_id' => (int) $request->annee_academique_id,
            ]);
            $diplome->setRelation('etudiant', $etudiant);

            $this->parkDocx($diplome, $request->batch);

            return response()->json(['success' => true, 'data' => [
                'etudiant_id' => $etudiant->id,
                'nom_prenom'  => $etudiant->nom_prenom,
                'filiere'     => $etudiant->groupe?->niveau?->filiere?->nom ?? 'Sans filière',
            ]]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Build the .docx for a batched diploma and move it into the batch folder,
     * one sub-folder per filière, so the archive step needs no bookkeeping.
     */
    private function parkDocx(Diplome $diplome, string $batch): string
    {
        $etudiant = $diplome->etudiant;
        $filiereNom = $etudiant?->groupe?->niveau?->filiere?->nom ?? 'Autres';

        $dir = storage_path('app/diploma_batch/' . $batch . '/' . $this->safeName($filiereNom));
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Création du dossier du lot impossible');
        }

        $temp = app(DiplomaDocxService::class)->build($diplome);

        $base = $this->safeName((string) $etudiant->nom_prenom) ?: 'Etudiant_' . $diplome->etudiant_id;
        $file = $dir . '/Diplome_' . $base . '.docx';
        if (file_exists($file)) {
            $file = $dir . '/Diplome_' . $base . '_' . $diplome->etudiant_id . '.docx';
        }

        if (!@rename($temp, $file)) {
            $moved = @copy($temp, $file);
            @unlink($temp);
            if (!$moved) {
                throw new \RuntimeException('Déplacement du .docx généré impossible');
            }
        }

        return $file;
    }

    /**
     * Filenames/folder names stripped of anything Windows rejects as a path
     * separator, while keeping accents and Arabic letters (NTFS is fine with
     * them). Never empty for a non-empty input.
     */
    private function safeName(string $value): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $value);
        return trim($clean === null ? '' : $clean, '._- ');
    }

    /**
     * Pack the batch folder (already split into one folder per filière) into a
     * single .rar and return its public URL, so the frontend triggers a native
     * browser download instead of streaming hundreds of MB through axios. Needs
     * WinRAR's Rar.exe to create the archive.
     */
    public function batchArchive(Request $request)
    {
        // The few-hundred-MB .rar (each .docx carries a full-page raster of the
        // template) streams straight to the browser: the default 30s execution
        // ceiling would kill it mid-transfer. Disable the limit for this request.
        set_time_limit(0);

        $request->validate([
            'batch'               => 'required|string|regex:/^[A-Za-z0-9_-]{1,64}$/',
            'annee_academique_id' => 'nullable|exists:annees_academiques,id',
        ]);

        $dir = storage_path('app/diploma_batch/' . $request->batch);

        if (!is_dir($dir) || !glob($dir . '/*/*.docx')) {
            return response()->json(['success' => false, 'message' => 'Aucun diplôme à compresser pour ce lot'], 400);
        }

        $rar = $this->rarBinary();
        if ($rar === null) {
            return response()->json([
                'success' => false,
                'message' => "WinRAR introuvable : installez-le ou définissez WINRAR_PATH dans .env (Rar.exe requis pour créer un .rar)",
            ], 500);
        }

        $out = $dir . '.rar';
        @unlink($out);

        $result = Process::timeout(300)->run([$rar, 'a', '-r', '-ep1', '-idq', $out, $dir . '\\*']);

        if (!$result->successful() || !is_file($out)) {
            $detail = trim($result->errorOutput() . ' ' . $result->output());
            return response()->json([
                'success' => false,
                'message' => 'Compression échouée' . ($detail !== '' ? ' : ' . $detail : ''),
            ], 500);
        }

            $label = null;
            if ($request->annee_academique_id) {
                $label = AnneeAcademique::find($request->annee_academique_id)?->label;
            }
        $safeLabel = $this->safeName((string) $label);
        $filename = ($safeLabel !== '' ? 'Diplomes_' . $safeLabel : 'Diplomes_' . $request->batch) . '.rar';

        // A few-hundred-MB .rar (each .docx carries a full-page raster of the
        // template) is streamed better by the browser than by PHP: park the
        // archive in the public disk and let the frontend trigger a native
        // download. Falls back to the old PHP stream when `storage:link` is
        // not set up.
        $publicDir = storage_path('app/public/diploma_zips');

        if (is_dir(public_path('storage')) || is_link(public_path('storage'))) {
            if (!is_dir($publicDir) && !@mkdir($publicDir, 0755, true) && !is_dir($publicDir)) {
                throw new \RuntimeException("Création du dossier des archives impossible");
            }

            $publicFile = $publicDir . '/' . $filename;
            @unlink($publicFile);

            if (!@rename($out, $publicFile)) {
                $moved = @copy($out, $publicFile);
                @unlink($out);
                if (!$moved) {
                    throw new \RuntimeException("Déplacement de l'archive impossible");
                }
            }

            // The files are inside the archive now; drop the folder so a re-run
            // of the same list starts clean.
            $this->deleteTree($dir);

            // Old archives eat disk fast (each is hundreds of MB): keep 24h.
            foreach (glob($publicDir . '/*.rar') ?: [] as $old) {
                if ($old !== $publicFile && filemtime($old) < time() - 86400) {
                    @unlink($old);
                }
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'filename' => $filename,
                    'path'     => url('/storage/diploma_zips/' . rawurlencode($filename)),
                ],
            ]);
        }

        // Files are inside the archive now; drop the folder so a re-run of the
        // same list starts clean.
        $this->deleteTree($dir);

        return response()->download($out, $filename, [
            'Content-Type' => 'application/vnd.rar',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Native, same-tab download of the archived .rar. The token travels in the
     * query string because a plain <a> cannot send Authorization headers; the
     * check is manual but strict: admin only, one exact filename below the
     * diploma_zips folder.
     */
    public function batchDownload(Request $request)
    {
        $token = (string) $request->query('token');
        $filename = (string) $request->query('filename');

        try {
            $accessToken = $token !== '' ? PersonalAccessToken::findToken($token) : null;
            $user = $accessToken?->tokenable ?? $request->user();

            if (!$user) {
                return response()->json(['message' => 'Non autorisé'], 401);
            }

            if ($user->role !== 'admin') {
                return response()->json(['message' => 'Non autorisé'], 403);
            }
        } catch (\Exception $e) {
            return response()->json(['message' => 'Non autorisé'], 401);
        }

        if (!preg_match('/^Diplomes_[A-Za-z0-9._-]+\.rar$/i', $filename)) {
            return response()->json(['message' => 'Archive introuvable'], 404);
        }

        $file = storage_path('app/public/diploma_zips/' . $filename);
        if (!is_file($file)) {
            return response()->json(['message' => 'Archive introuvable : relancez la génération'], 404);
        }

        set_time_limit(0);

        return response()->download($file, $filename, [
            'Content-Type' => 'application/vnd.rar',
        ]);
    }

    /**
     * WinRAR's CLI, the only tool that can CREATE a .rar. Configurable for a
     * non-default install location, then the usual paths, then PATH.
     */
    private function rarBinary(): ?string
    {
        $candidates = array_filter([
            (string) config('diplomes.rar_path'),
            'C:\\Program Files\\WinRAR\\Rar.exe',
            'C:\\Program Files (x86)\\WinRAR\\Rar.exe',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) return $path;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $path = rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . 'Rar.exe';
            if (is_file($path)) return $path;
        }

        return null;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    public function markPrinted($id)
    {
        try {
            $diplome = Diplome::findOrFail($id);
            $diplome->update(['is_printed' => true, 'printed_at' => now()]);
            return response()->json(['success' => true, 'data' => $diplome]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour'], 500);
        }
    }

    /**
     * Download the editable .docx diploma: A4-landscape page, a full-page raster
     * of the filière's template in the background and every field as an editable
     * floating text box.
     */
    public function download($id)
    {
        try {
            $diplome = Diplome::with([
                'etudiant.groupe.niveau.filiere',
                'anneeAcademique'
            ])->findOrFail($id);

            // Legacy diplomas can outlive their student (the student row was
            // removed). Building the document dereferences etudiant->groupe, so
            // answer a clear 422 instead of a 500 for such orphans.
            if (! $diplome->etudiant || ! $diplome->etudiant->groupe) {
                return response()->json([
                    'success' => false,
                    'message' => "Le diplôme {$diplome->id} est lié à un étudiant introuvable ; le document ne peut pas être généré.",
                ], 422);
            }

            $service = app(DiplomaDocxService::class);
            $tempFile = $service->build($diplome);

            $nom = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $diplome->etudiant->nom_prenom);
            $filename = 'Diplome_' . trim($nom, '_') . '.docx';

            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Templates + calibrated field positions, consumed by the front-end
     * calibration overlay. Single source of truth is config/diplomes.php.
     */
    public function templatesMeta()
    {
        try {
            $pageW = (float) config('diplomes.page_width_pt');
            $service = app(DiplomaDocxService::class);

            $templates = [];
            foreach (config('diplomes.fields') as $type => $fields) {
                $templates[] = [
                    'type' => $type,
                    'template_url' => config('diplomes.templates.' . $type),
                    'fields' => $fields,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'page_width_mm' => round($pageW * 25.4 / 72, 1),
                    'page_height_mm' => 210,
                    'templates' => $templates,
                    // The establishment-level values and the calibrated
                    // marker positions the calibration overlay renders.
                    'infos' => config('diplomes.infos', []),
                    'info_positions' => $service->allInfoPositions(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * The establishment-level values printed inside the diploma's
     * certification text (same for every student), plus the info definitions
     * and their current marker positions per template type.
     */
    public function informations()
    {
        try {
            $service = app(DiplomaDocxService::class);

            return response()->json([
                'success' => true,
                'data' => [
                    'infos' => config('diplomes.infos', []),
                    'valeurs' => $service->informations(),
                    'positions' => $service->allInfoPositions(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function saveInformations(Request $request)
    {
        $rules = [
            'valeurs' => 'required|array',
            'valeurs.*' => 'nullable|string|max:120',
        ];

        // The date-typed informations come from a date picker; reject anything
        // that is not a real date so a typo cannot reach the printed diploma.
        foreach (config('diplomes.infos', []) as $info) {
            if (($info['type'] ?? 'text') === 'date') {
                $rules['valeurs.' . $info['key']] = 'nullable|date';
            }
        }

        $request->validate($rules);

        try {
            app(DiplomaDocxService::class)->saveInformations($request->input('valeurs', []));

            return response()->json(['success' => true, 'message' => 'Informations du diplôme enregistrées']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Erreur lors de l'enregistrement"], 500);
        }
    }

    /**
     * Save the calibrated positions of the information fields, for any or
     * all template types at once: { positions: { <type>: { <key>: { fr, ar } } } }.
     */
    public function saveInformationsPositions(Request $request)
    {
        $request->validate([
            'positions' => 'required|array',
        ]);

        try {
            app(DiplomaDocxService::class)->saveInfoPositions($request->input('positions', []));

            return response()->json(['success' => true, 'message' => 'Positions enregistrées']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => "Erreur lors de l'enregistrement des positions"], 500);
        }
    }

    public function checkReadiness(Request $request)
    {
        $request->validate([
            'annee_academique_id' => 'required|exists:annees_academiques,id',
        ]);

        try {
            $anneeId = $request->annee_academique_id;

            $etudiants = Etudiant::with('groupe.niveau.filiere')
                ->where('annee_academique_id', $anneeId)
                ->get();

            $ready = 0;
            $notFinalYear = 0;
            $bulletinNotPublished = 0;
            $notesIncomplete = 0;
            $moyenneInsufficient = 0;
            $orphans = 0;
            $errors = [];

            foreach ($etudiants as $etudiant) {
                // Per-student, so one ungroupable record cannot take the whole
                // response down. etudiants.groupe_id is ON DELETE SET NULL, so a
                // student can genuinely have no group, and the outer catch below
                // used to turn that single row into a 500 for all other students.
                try {
                    $niveau = $etudiant->groupe?->niveau;
                    $filiere = $niveau?->filiere;

                    // No group means no curriculum, so this row can never be judged.
                    // Counted apart from notFinalYear so the admin sees why.
                    if (!$niveau) {
                        $orphans++;
                        continue;
                    }

                    if (!$filiere || $niveau->numero !== $filiere->nombre_annees) {
                        $notFinalYear++;
                        continue;
                    }

                    if (!$this->checkBulletinPublished($etudiant, $anneeId)) {
                        $bulletinNotPublished++;
                        continue;
                    }

                    if (!$this->checkAllNotesFilled($etudiant, $anneeId)) {
                        $notesIncomplete++;
                        continue;
                    }

                    $bulletin = $this->bulletinService->calculateBulletin($etudiant->id, $anneeId);
                    $moyenne = $bulletin['moyenne_generale'];

                    // !$moyenne also catches a legitimate 0/20, which is below the
                    // pass mark either way, so folding it in here changes nothing.
                    if (!$moyenne || $moyenne < 10) {
                        $moyenneInsufficient++;
                        continue;
                    }

                    $ready++;
                } catch (\Throwable $e) {
                    $orphans++;
                    $errors[] = ($etudiant->nom_prenom ?? "Étudiant #{$etudiant->id}") . ': ' . $e->getMessage();
                }
            }

            $blocked = $bulletinNotPublished > 0 || $notesIncomplete > 0;

            $message = null;
            if ($blocked) {
                $parts = [];
                if ($bulletinNotPublished > 0) {
                    $parts[] = "{$bulletinNotPublished} étudiant(s) sans bulletin publié";
                }
                if ($notesIncomplete > 0) {
                    $parts[] = "{$notesIncomplete} étudiant(s) avec notes incomplètes";
                }
                $message = 'Génération impossible : ' . implode(', ', $parts) . '.';
            }

            if ($orphans > 0) {
                $message = trim(($message ? $message . ' ' : '')
                    . "{$orphans} étudiant(s) sans groupe exploitable : à corriger manuellement.");
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'ready' => $ready,
                    'not_final_year' => $notFinalYear,
                    'bulletin_not_published' => $bulletinNotPublished,
                    'notes_incomplete' => $notesIncomplete,
                    'moyenne_insufficient' => $moyenneInsufficient,
                    'orphans' => $orphans,
                    'total' => $etudiants->count(),
                    'blocked' => $blocked,
                    'message' => $message,
                    'errors' => $errors,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function generateAll(Request $request)
    {
        try {
            $request->validate([
                'annee_academique_id' => 'required|exists:annees_academiques,id',
            ]);

            $anneeId = $request->annee_academique_id;

            $etudiants = Etudiant::with('groupe.niveau.filiere')
                ->where('annee_academique_id', $anneeId)
                ->get();

            $created = 0;
            $skipped = 0;
            $errors = [];

            foreach ($etudiants as $etudiant) {
                try {
                    $niveau = $etudiant->groupe?->niveau;
                    $filiere = $niveau?->filiere;

                    if (!$niveau || !$filiere || $niveau->numero !== $filiere->nombre_annees) {
                        $skipped++;
                        continue;
                    }

                    if (!$this->checkBulletinPublished($etudiant, $anneeId)) {
                        $skipped++;
                        continue;
                    }

                    if (!$this->checkAllNotesFilled($etudiant, $anneeId)) {
                        $skipped++;
                        continue;
                    }

                    $bulletin = $this->bulletinService->calculateBulletin(
                        $etudiant->id,
                        $anneeId
                    );

                    $moyenne = $bulletin['moyenne_generale'];

                    if (!$moyenne || $moyenne < 10) {
                        $skipped++;
                        continue;
                    }

                    Diplome::updateOrCreate(
                        ['etudiant_id' => $etudiant->id, 'annee_academique_id' => $anneeId],
                        ['moyenne_generale' => $moyenne, 'mention' => $this->bulletinService->getMention($moyenne)['label']]
                    );

                    $etudiant->update(['status' => 'graduate']);
                    $created++;
                } catch (\Throwable $e) {
                    // Throwable, not Exception: an Error here means one bad row, and
                    // silently counting it as "skipped" hides a real fault behind a
                    // tidy summary. Recorded per student instead.
                    $skipped++;
                    $errors[] = ($etudiant->nom_prenom ?? "Étudiant #{$etudiant->id}") . ': ' . $e->getMessage();
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'created' => $created,
                    'skipped' => $skipped,
                    'total'   => $etudiants->count(),
                    'errors'  => $errors,
                ],
                'message' => "{$created} diplôme(s) généré(s), {$skipped} non-admis/non-diplômables"
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
