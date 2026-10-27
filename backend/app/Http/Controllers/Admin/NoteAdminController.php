<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Note;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\MoyenneService;
use App\Services\PublicationLockService;
use App\Services\SoutenanceService;
use App\Support\Curriculum\OfficialCurriculum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NoteAdminController extends Controller
{
    protected $moyenneService;
    protected $bulletinService;
    protected $locks;
    protected $soutenanceService;

    public function __construct(
        MoyenneService $moyenneService,
        BulletinService $bulletinService,
        PublicationLockService $locks,
        SoutenanceService $soutenanceService
    ) {
        $this->moyenneService = $moyenneService;
        $this->bulletinService = $bulletinService;
        $this->locks = $locks;
        $this->soutenanceService = $soutenanceService;
    }

    public function index(Request $request)
    {
        try {
            $currentAnnee = \App\Models\AnneeAcademique::where('is_current', true)->first();
            $anneeId = $request->annee_academique_id ?? $currentAnnee?->id;

            $notes = Note::with(['etudiant', 'controle.sequence.unite'])
                ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
                ->when($request->sequence_id, fn($q) => $q->whereIn(
                    'controle_id',
                    Controle::where('sequence_id', $request->sequence_id)->pluck('id')
                ))
                // A stage unit's controls hang directly off the unit (no
                // sequence), so the saisie grid asks by unite_id and must see
                // both the direct controls and any sequence-borne controls.
                ->when($request->unite_id, fn($q) => $q->whereIn(
                    'controle_id',
                    Controle::where(function ($c) use ($request) {
                        $c->where('unite_id', $request->unite_id)
                            ->orWhereHas('sequence', fn($s) => $s->where('unite_id', $request->unite_id));
                    })->pluck('id')
                ))
                ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
                ->when(
                    $anneeId,
                    fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('annee_academique_id', $anneeId)),
                    // No explicit year and no current year: return nothing rather
                    // than mixing every year's grades.
                    fn($q) => $q->whereRaw('0 = 1')
                )
                ->get();
            return response()->json(['success' => true, 'data' => $notes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des notes'], 500);
        }
    }

    /**
     * Create the first grade of a control.
     *
     * NoteAdminController::index only ever returns existing rows, so a sequence
     * that has never been graded produced no rows at all and the admin could not
     * enter anything. This endpoint lets the saisie grid open a cell that has no
     * Note yet. It mirrors update(): same publication guard, same per-control
     * note_max scale, same "absence is a zero" rule.
     */
    public function store(Request $request)
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'controle_id' => 'required|exists:controles,id',
            'valeur'     => 'nullable|numeric',
            'est_absent' => 'nullable|boolean',
        ]);

        try {
            $etudiant = Etudiant::findOrFail($request->etudiant_id);
            $controle = Controle::with(['sequence.unite', 'unite'])->findOrFail($request->controle_id);

            // A stage control has no sequence, so its semester comes straight
            // off the unit.
            $semestre = $controle->sequence?->unite?->semestre ?? $controle->unite?->semestre;

            if ($this->locks->semestreVerrouille(
                (int) $etudiant->groupe_id,
                (int) $etudiant->annee_academique_id,
                (int) $semestre
            )) {
                return response()->json(['success' => false, 'message' => 'Impossible de modifier une note publiée'], 403);
            }

            $noteMax = $controle->note_max ?: 20;
            $estAbsent = $request->boolean('est_absent');
            $validator = Validator::make($request->all(), [
                'valeur' => [$estAbsent ? 'nullable' : 'required', 'numeric', 'min:0', "max:{$noteMax}"],
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => $validator->errors()->first(),
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $note = Note::updateOrCreate(
                ['etudiant_id' => $etudiant->id, 'controle_id' => $controle->id],
                [
                    'valeur'     => $estAbsent ? 0 : $request->valeur,
                    'est_absent' => $estAbsent,
                ]
            );

            $this->logNoteChange(
                $etudiant,
                $controle,
                $estAbsent ? 'absent' : (string) $request->valeur,
                'a enregistré une note'
            );

            return response()->json([
                'success' => true,
                'data'    => $note,
                'message' => $estAbsent ? 'Marqué absent' : 'Note enregistrée',
            ], 201);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la note'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $note = Note::with(['controle.sequence.unite', 'controle.unite', 'etudiant'])->findOrFail($id);

        $groupeId = $note->etudiant?->groupe_id;
        $semestre = $note->controle?->sequence?->unite?->semestre ?? $note->controle?->unite?->semestre;
        $anneeAcademiqueId = $note->etudiant?->annee_academique_id;

        if ($this->locks->semestreVerrouille((int) $groupeId, (int) $anneeAcademiqueId, (int) $semestre)) {
            return response()->json(['success' => false, 'message' => 'Impossible de modifier une note publiée'], 403);
        }

        // Each control carries its own scale (note_max), so a value must be
        // checked against that scale rather than a hardcoded 20.
        $noteMax = $note->controle?->note_max ?: 20;
        $estAbsent = $request->boolean('est_absent');
        $request->validate([
            'valeur'     => [$estAbsent ? 'nullable' : 'required', 'numeric', 'min:0', "max:{$noteMax}"],
            'est_absent' => 'nullable|boolean',
        ]);

        try {
            $ancienne = $note->est_absent ? 'absent' : (string) $note->valeur;

            $note->update([
                'valeur'     => $estAbsent ? 0 : $request->valeur,
                'est_absent' => $estAbsent,
            ]);

            $this->logNoteChange(
                $note->etudiant,
                $note->controle,
                $estAbsent ? 'absent' : (string) $request->valeur,
                'a modifié une note',
                $ancienne
            );

            return response()->json(['success' => true, 'data' => $note, 'message' => $estAbsent ? 'Marqué absent' : 'Note mise à jour']);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::update error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la note'], 500);
        }
    }

    /**
     * Journal entry for a single grade: who the student is, which control and
     * unit, and the old → new value. The name-less grouping in the UI already
     * prefixes the admin's name, so the description starts with the verb.
     */
    private function logNoteChange(
        ?Etudiant $etudiant,
        ?Controle $controle,
        string $nouvelle,
        string $verbe,
        ?string $ancienne = null
    ): void {
        if (! $etudiant || ! $controle) {
            return;
        }

        $admin = Auth::user();
        if (! $admin) {
            return;
        }

        $unite = $controle->sequence?->unite ?? $controle->unite;

        $description = "{$verbe} — {$etudiant->nom_prenom}" . ($etudiant->numero_inscription ? " ({$etudiant->numero_inscription})" : "");
        $description .= ' — Contrôle « ' . ($controle->nom ?: ('C' . $controle->numero)) . ' »';
        if ($unite?->nom) {
            $description .= " — Unité « {$unite->nom} »";
            if ($unite->coefficient) {
                $description .= " (coef. {$unite->coefficient})";
            }
        }
        if ($controle->sequence?->nom) {
            $description .= " — Séquence « {$controle->sequence->nom} »";
        }
        if ($controle->note_max) {
            $description .= " /{$controle->note_max}";
        }
        $description .= $ancienne !== null
            ? " : {$ancienne} → {$nouvelle}"
            : " : {$nouvelle}";

        ActivityLog::create([
            'admin_id'    => $admin->id,
            'admin_name'  => $admin->name,
            'action_type' => 'update',
            'description' => $description,
            'model_type'  => 'App\Models\Note',
            'model_id'    => $etudiant->id,
        ]);
    }

    public function recapNotes(Request $request)
    {
        $request->validate([
            'groupe_id' => 'required|exists:groupes,id',
            'type' => 'required|string',
        ]);

        try {
            $data = $this->computeRecapData((int) $request->groupe_id, $request->type);
            $data['available_tables'] = $this->availableTableTypes((int) $request->groupe_id);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::recapNotes error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement du relevé'], 500);
        }
    }

    /**
     * Serve the same recap grid as a printable .docx jury sheet, styled after
     * the reference recap document (logo header, footer, jury / filière / grade
     * / signature tables), with the jury names left blank for handwriting.
     */
    public function recapDocx(Request $request)
    {
        $request->validate([
            'groupe_id' => 'required|exists:groupes,id',
            'type' => 'required|string',
        ]);

        try {
            $data = $this->computeRecapData((int) $request->groupe_id, $request->type);
            $data['table_type'] = $request->type;

            $service = app(\App\Services\RecapNoteDocxService::class);
            $path = $service->build($data);

            return response()->download($path, $service->buildFilename($data), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::recapDocx error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération du relevé'], 500);
        }
    }

    /**
     * Serve every recap table of the group's filière as one .docx, one sheet per
     * page, so the whole filière can be printed in a single download.
     */
    public function recapDocxAll(Request $request)
    {
        $request->validate([
            'groupe_id' => 'required|exists:groupes,id',
        ]);

        try {
            $service = app(\App\Services\RecapNoteDocxService::class);

            $datasets = [];
            foreach ($this->availableTableTypes((int) $request->groupe_id) as $type) {
                $data = $this->computeRecapData((int) $request->groupe_id, $type);
                $data['table_type'] = $type;
                $datasets[] = $data;
            }

            $path = $service->buildAll($datasets);

            return response()->download($path, $service->buildAllFilename($datasets[0]), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::recapDocxAll error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération des relevés'], 500);
        }
    }

    /**
     * The list of recap tables a group actually has, so the "Tableau" picker
     * never offers NSTI (no soutenance) or Stage (no Stage unit).
     */
    public function recapTableTypes(Request $request)
    {
        $request->validate(['groupe_id' => 'required|exists:groupes,id']);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->availableTableTypes((int) $request->groupe_id),
            ]);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::recapTableTypes error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des tableaux'], 500);
        }
    }

    /**
     * The recap tables that exist for a group's filière/year, in the reference
     * order. Two of them are conditional:
     *
     *  - "nsti" only exists for the programmes whose final year ends with a
     *    soutenance (aSoutenance()), exactly like the bulletin's NSTI component;
     *  - "stage" only exists when the filière/year has an active Stage unit.
     *
     * Every other table is always available.
     */
    public function availableTableTypes(int $groupeId): array
    {
        $groupe = \App\Models\Groupe::with('niveau')->findOrFail($groupeId);
        $filiereId = $groupe->niveau->filiere_id;
        $numeroAnnee = $groupe->niveau->numero;

        $types = \App\Services\RecapNoteDocxService::TABLE_TYPES;

        if (!$this->soutenanceService->aSoutenance($filiereId, $numeroAnnee)) {
            $types = array_values(array_diff($types, ['nsti']));
        }

        $hasStage = Unite::where('nom', 'Stage')
            ->where('filiere_id', $filiereId)
            ->where('numero_annee', $numeroAnnee)
            ->where('is_active', true)
            ->exists();
        if (!$hasStage) {
            $types = array_values(array_diff($types, ['stage']));
        }

        return $types;
    }

    /**
     * The coefficient printed in a synthesis table: the official weight x100. It
     * stays a whole number when the weight is exact (30 / 20 / 30 / 20) and a
     * two-decimal value when it is not (36,67 / 26,66 / 36,67), so the relevé's
     * coefficient row always matches the bulletin's percentages.
     *
     * @return int|float
     */
    private function coefficientOf(float $weight)
    {
        $value = round($weight * 100, 2);

        return abs($value - round($value)) < 0.005 ? (int) round($value) : $value;
    }

    /**
     * Shared computation behind recapNotes() and recapDocx(), grouping the
     * website readable units of a filière/niveau into the grid rows.
     */
    private function computeRecapData(int $groupeId, string $type): array
    {
        $groupe = \App\Models\Groupe::with('niveau.filiere')->findOrFail($groupeId);

        $filiereId = $groupe->niveau->filiere_id;
        $niveauNumero = $groupe->niveau->numero;

        $semestre = null;
        $controleType = null;
        $examType = null;
        $examBloc = null;
        $nsti = false;
        $stage = false;
        $synthese = false;

        if (preg_match('/^(mpcc|mpefcf|mpefcfp)(\d)$/', $type, $m)) {
            $semestre = (int) $m[2];
            if ($m[1] === 'mpcc') {
                $controleType = 'cc';
            } elseif ($m[1] === 'mpefcf') {
                $examType = 'theorique';
                $examBloc = $semestre;
            } elseif ($m[1] === 'mpefcfp') {
                $examType = 'pratique';
                $examBloc = $semestre;
            }
        } elseif ($type === 'nsti') {
            // NSTI sheet: the jury grid. Its criteria are the direct controls of
            // the filière's "Soutenance" unit, so each column reads that unit's
            // notes for the criterion.
            $nsti = true;
        } elseif ($type === 'stage') {
            // Stage sheet: the jury's Stage marks, one Évaluation column and one
            // Examen pratique column, both read from the filière's Stage unit.
            $stage = true;
        } elseif ($type === 'synthese') {
            // Final synthesis sheet: the four global averages (MPCC, theoretical,
            // practical, NSTI) and their 30/20/30/20 weighted final mark NF.
            $synthese = true;
        } elseif ($type === 'mpcc_global' || $type === 'mpefcf_global' || $type === 'mpefcfp_global') {
            // Global tables merge every bloc into a single list of unites.
            if ($type === 'mpcc_global') {
                $controleType = 'cc';
            } elseif ($type === 'mpefcf_global') {
                $examType = 'theorique';
            } else {
                $examType = 'pratique';
            }
        }

        // The Stage spans the whole year, so it is never an S1/S2 unit. It is
        // kept out of every per-semestre table (MPCC1/2 and the Bloc 1/Bloc 2
        // exam tables) and out of the full-year théorique table too. It only
        // remains where the reference shows it: the full-year MPCC (fin de
        // cursus) and the full-year pratique exams.
        //
        // The Soutenance unit is not a MPCC/exam unit at all: it only feeds the
        // NSTI sheet, so it is kept out of every one of these tables.
        $stageTables = ['mpcc_global', 'mpefcfp_global'];

        $unites = ($nsti || $stage || $synthese) ? collect() : Unite::where('filiere_id', $filiereId)
            ->where('numero_annee', $niveauNumero)
            ->where('is_active', true)
            ->when($semestre, fn($q) => $q->where('semestre', $semestre))
            ->where('nom', '!=', SoutenanceService::UNITE_NOM)
            ->when(!in_array($type, $stageTables, true), fn($q) => $q->where('nom', '!=', 'Stage'))
            ->orderBy('ordre')
            ->get();

        // The NSTI criteria notes are the ordinary notes of the university
        // "Soutenance" unit's controls (one per criterion, numbered 1..10).
        $soutenanceUnite = $nsti
            ? $this->soutenanceService->unitePour($filiereId, $niveauNumero)
            : null;
        $soutenanceControles = $soutenanceUnite?->controles->keyBy('numero') ?? collect();

        // The Stage sheet reads the filière's Stage unit for this year (one per
        // filière/year, coefficient 4). A filière without one renders blank.
        $stageUnite = $stage
            ? Unite::where('filiere_id', $filiereId)
                ->where('numero_annee', $niveauNumero)
                ->where('nom', 'Stage')
                ->where('is_active', true)
                ->first()
            : null;

        $etudiants = Etudiant::where('groupe_id', $groupeId)
            ->orderBy('nom_prenom')
            ->get();

        $anneeId = $groupe->annee_academique_id;

        // Build one subject per unite (not per sequence). The NSTI sheet uses
        // its fixed jury criteria instead of the filière's units.
        $subjects = [];
        if ($nsti) {
            foreach (OfficialCurriculum::NSTI_CRITERIA as $i => $criterion) {
                $subjects[] = [
                    'id' => 'nsti_' . ($i + 1),
                    'nom' => $criterion['nom'],
                    'coefficient' => $criterion['coefficient'],
                ];
            }
        } elseif ($stage) {
            // Two columns on a single Stage row: the mean of the per-service
            // Évaluation marks and the mean of the per-service Examen marks,
            // both carried by the filière's Stage unit (coefficient 4, as in the
            // reference, or the unit's own coefficient).
            $stageCoef = (int) ($stageUnite?->coefficient ?? 4);
            $subjects[] = ['id' => 'stage_eval', 'nom' => "Note d'évaluation", 'coefficient' => $stageCoef];
            $subjects[] = ['id' => 'stage_exam', 'nom' => "Note d'examen pratique", 'coefficient' => $stageCoef];
        } elseif ($synthese) {
            // Final synthesis: the global averages weighted by the official
            // final-mark formula, the same weights (and the same NSTI on/off
            // rule) the bulletin's NF uses: a final-year soutenance programme is
            // 30/20/30/20, every other one 36,67/26,66/36,67 with no NSTI column.
            $hasSoutenance = $this->soutenanceService->aSoutenance($filiereId, $niveauNumero);
            $weights = BulletinService::componentWeights($hasSoutenance);

            $subjects[] = ['id' => 'mpcc', 'nom' => 'MPCC*', 'coefficient' => $this->coefficientOf($weights['cc'])];
            $subjects[] = ['id' => 'mpefcf', 'nom' => 'MPEFCFT**', 'coefficient' => $this->coefficientOf($weights['theorique'])];
            $subjects[] = ['id' => 'mpefcfp', 'nom' => 'MPEFCFP***', 'coefficient' => $this->coefficientOf($weights['pratique'])];
            if ($hasSoutenance) {
                $subjects[] = ['id' => 'nsti', 'nom' => 'NSTI****', 'coefficient' => $this->coefficientOf($weights['nsti'])];
            }
        } else {
            foreach ($unites as $unite) {
                $subjects[] = [
                    'id' => 'u_' . $unite->id,
                    'nom' => $unite->nom,
                    'coefficient' => $unite->coefficient,
                ];
            }
        }

        // The synthesis columns are the students' averages on the matching
        // global sheets, so build those grids once and index them by student.
        $syntheseValues = [];
        if ($synthese) {
            $sources = [
                'mpcc' => 'mpcc_global',
                'mpefcf' => 'mpefcf_global',
                'mpefcfp' => 'mpefcfp_global',
            ];
            if ($this->soutenanceService->aSoutenance($filiereId, $niveauNumero)) {
                $sources['nsti'] = 'nsti';
            }
            foreach ($sources as $key => $sourceType) {
                $grid = $this->computeRecapData($groupeId, $sourceType);
                foreach ($grid['students'] as $row) {
                    $syntheseValues[$row['id']][$key] = $row['average'];
                }
            }
        }

        // Preload examens for mpefcf/mpefcfp types. The bloc is decided by
        // the unite's semestre (same rule as MPC): the unite list above is
        // already scoped to the bloc, so `examens.bloc` is never trusted.
        $examensByEtudiant = [];
        if ($examType) {
            $examRows = Examen::whereIn('etudiant_id', $etudiants->pluck('id'))
                ->whereIn('unite_id', $unites->pluck('id'))
                ->where('type', $examType)
                ->where('annee_academique_id', $anneeId)
                ->get();
            foreach ($examRows as $ex) {
                $examensByEtudiant[$ex->etudiant_id][$ex->unite_id] = $ex->valeur;
            }
        }

        $students = [];
        foreach ($etudiants as $etudiant) {
            $notes = [];
            $totalNote = 0;
            $totalCoef = 0;

            foreach ($subjects as $subject) {
                $avg = null;

                if ($nsti) {
                    // The jury criterion is the n-th control of the Soutenance
                    // unit; a criterion the jury has not graded yet stays blank.
                    $numero = (int) substr($subject['id'], strlen('nsti_'));
                    $controle = $soutenanceControles->get($numero);
                    $note = $controle?->notes->firstWhere('etudiant_id', $etudiant->id);
                    $avg = ($note && $note->valeur !== null) ? (float) $note->valeur : null;
                } elseif ($stage) {
                    // Évaluation / Examen mean of the Stage unit; blank when the
                    // filière has no active Stage unit for this year.
                    if ($stageUnite) {
                        $avg = $subject['id'] === 'stage_eval'
                            ? $this->moyenneService->moyenneStageEvaluation($etudiant->id, $stageUnite)
                            : $this->moyenneService->moyenneStageExamen($etudiant->id, $stageUnite);
                    }
                } elseif ($synthese) {
                    // The column value is the student's average on the matching
                    // global sheet.
                    $avg = $syntheseValues[$etudiant->id][$subject['id']] ?? null;
                } else {
                    $uniteId = (int) substr($subject['id'], 2);

                    if ($controleType !== null) {
                        // MPCC: the Stage's CC is the average of its services'
                        // Évaluation marks (the Examen side never feeds MPCC);
                        // every other unit uses its usual unit average.
                        $uniteModel = $unites->firstWhere('id', $uniteId);
                        $avg = ($uniteModel && $uniteModel->nom === 'Stage')
                            ? $this->moyenneService->moyenneStageEvaluation($etudiant->id, $uniteModel)
                            : $this->moyenneService->moyenneUnite($etudiant->id, $uniteId, $anneeId);
                    } elseif ($examType) {
                        // MPEFCF/MPEFCFP: read from the examens table. The Stage
                        // keeps its exams in `stage_notes`, so its Pratique
                        // column is the average of those per-service marks.
                        $uniteModel = $unites->firstWhere('id', $uniteId);
                        if ($examType === 'pratique' && $uniteModel && $uniteModel->nom === 'Stage') {
                            $avg = $this->moyenneService->moyenneStageExamen($etudiant->id, $uniteModel);
                        } else {
                            $avg = $examensByEtudiant[$etudiant->id][$uniteId] ?? null;
                        }
                    }
                }

                $notes[$subject['id']] = $avg;
                if ($avg !== null) {
                    $totalNote += $avg * $subject['coefficient'];
                    $totalCoef += $subject['coefficient'];
                }
            }

            $students[] = [
                'id' => $etudiant->id,
                'nom_prenom' => $etudiant->nom_prenom,
                'notes' => $notes,
                'average' => $totalCoef > 0 ? round($totalNote / $totalCoef, 2) : null,
            ];
        }

        $classSubjects = [];
        foreach ($subjects as $subject) {
            $vals = [];
            foreach ($students as $s) {
                if ($s['notes'][$subject['id']] !== null) {
                    $vals[] = $s['notes'][$subject['id']];
                }
            }
            $classSubjects[$subject['id']] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 2) : null;
        }

        $overallVals = [];
        foreach ($students as $s) {
            if ($s['average'] !== null) $overallVals[] = $s['average'];
        }
        $classOverall = count($overallVals) > 0 ? round(array_sum($overallVals) / count($overallVals), 2) : null;

        return [
            'students' => $students,
            'subjects' => $subjects,
            // The Stage and synthesis sheets have no class-average row in the
            // reference, so expose none: the grid and the .docx both skip it.
            'class_average' => ($stage || $synthese) ? null : [
                'subjects' => $classSubjects,
                'overall' => $classOverall,
            ],
            'header' => [
                'filiere' => $groupe->niveau->filiere->nom ?? '',
                'section' => $groupe->niveau->filiere->section ?? '',
                'groupe' => $groupe->nom ?? '',
                'annee_scolaire' => $groupe->annee_academique_id ? \App\Models\AnneeAcademique::find($groupe->annee_academique_id)?->label : '',
                'annee_formation' => $groupe->promotion ?? '',
            ],
        ];
    }

    public function bulletin(Request $request)
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'groupe_id' => 'required|exists:groupes,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'semestre' => 'nullable|integer|in:1,2',
        ]);

        try {
            $bulletin = $this->bulletinService->calculateBulletin(
                $request->etudiant_id,
                $request->annee_academique_id,
                $request->semestre
            );

            $mention = $this->bulletinService->getMention($bulletin['moyenne_generale']);

            $semesters = [1 => ['unites' => []], 2 => ['unites' => []]];
            foreach ($bulletin['unites'] as $u) {
                $semestre = (int) ($u['semestre'] ?? 0);
                if ($semestre === 0) {
                    $semesters[1]['unites'][] = $u;
                    $semesters[2]['unites'][] = $u;
                    continue;
                }
                if (isset($semesters[$semestre])) {
                    $semesters[$semestre]['unites'][] = $u;
                }
            }

            return response()->json(['success' => true, 'data' => [
                'student' => $bulletin['student'],
                'semesters' => $semesters,
                'moyenne_generale' => $bulletin['moyenne_generale'],
                'moyenne_cc' => $bulletin['moyenne_cc'],
                'moyenne_theorique' => $bulletin['moyenne_theorique'],
                'moyenne_pratique' => $bulletin['moyenne_pratique'],
                'nsti' => $bulletin['nsti'],
                'has_soutenance' => $bulletin['has_soutenance'],
                'weights' => $bulletin['weights'],
                'decision' => $bulletin['decision'],
                'mention' => $mention,
                'total_coef_cc' => $bulletin['total_coef_cc'],
                'total_coef_theorique' => $bulletin['total_coef_theorique'],
                'total_coef_pratique' => $bulletin['total_coef_pratique'],
            ]]);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::bulletin error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement du bulletin'], 500);
        }
    }

    /**
     * The .docx twin of the on-screen bulletin: one A4-portrait page, styled
     * after the reference bulletin document.
     */
    public function bulletinDocx(Request $request)
    {
        $request->validate([
            'etudiant_id' => 'required|exists:etudiants,id',
            'groupe_id' => 'required|exists:groupes,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'semestre' => 'nullable|integer|in:1,2',
        ]);

        try {
            $groupe = \App\Models\Groupe::with('niveau.filiere')->findOrFail((int) $request->groupe_id);
            $context = $this->bulletinDocxContext($groupe, (int) $request->annee_academique_id);
            $bulletin = $this->bulletinPayload($this->bulletinService->calculateBulletin(
                (int) $request->etudiant_id,
                (int) $request->annee_academique_id,
                $request->semestre ? (int) $request->semestre : null
            ));

            $service = app(\App\Services\BulletinDocxService::class);
            $path = $service->build($bulletin, $context);

            return response()->download($path, $service->buildFilename($bulletin, $context), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::bulletinDocx error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération du bulletin'], 500);
        }
    }

    /**
     * Every student of the group as one .docx, one bulletin per page, in the
     * reference order (numero_inscription ascending).
     */
    public function bulletinDocxAll(Request $request)
    {
        $request->validate([
            'groupe_id' => 'required|exists:groupes,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'semestre' => 'nullable|integer|in:1,2',
        ]);

        try {
            $groupe = \App\Models\Groupe::with('niveau.filiere')->findOrFail((int) $request->groupe_id);
            $context = $this->bulletinDocxContext($groupe, (int) $request->annee_academique_id);
            $semestre = $request->semestre ? (int) $request->semestre : null;

            $bulletins = [];
            foreach (Etudiant::where('groupe_id', $groupe->id)->orderBy('numero_inscription')->get() as $etudiant) {
                $bulletins[] = $this->bulletinPayload($this->bulletinService->calculateBulletin(
                    $etudiant->id,
                    (int) $request->annee_academique_id,
                    $semestre
                ));
            }

            $service = app(\App\Services\BulletinDocxService::class);
            $path = $service->buildAll($bulletins, $context);

            return response()->download($path, $service->buildAllFilename($context), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::bulletinDocxAll error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération des bulletins'], 500);
        }
    }

    /**
     * Page context shared by the bulletin docx endpoints: the filière / section
     * / year shown in the identity block and the page title.
     */
    private function bulletinDocxContext(\App\Models\Groupe $groupe, int $anneeAcademiqueId): array
    {
        $filiere = $groupe->niveau->filiere;
        $numero = (int) $groupe->niveau->numero;
        $nombreAnnees = (int) ($filiere->nombre_annees ?? 0);
        if ($nombreAnnees <= 0) {
            $nombreAnnees = $numero;
        }

        return [
            'filiere' => $filiere->nom ?? '',
            'section' => $filiere->section ?? '',
            'annee_scolaire' => \App\Models\AnneeAcademique::find($anneeAcademiqueId)?->label ?? '',
            'annee_formation' => $groupe->promotion ?? '',
            'title' => app(\App\Services\BulletinDocxService::class)->titleFor($numero, $nombreAnnees),
        ];
    }

    /**
     * Flatten a BulletinService result into the shape the docx service reads:
     * its identity keys sit at the top level.
     */
    private function bulletinPayload(array $bulletin): array
    {
        return $bulletin + [
            'nom_prenom' => $bulletin['student']->nom_prenom ?? '',
            'numero_inscription' => $bulletin['student']->numero_inscription ?? '',
        ];
    }
}
