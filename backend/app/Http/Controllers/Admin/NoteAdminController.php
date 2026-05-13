<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\MoyenneService;
use Illuminate\Http\Request;

class NoteAdminController extends Controller
{
    protected $moyenneService;
    protected $bulletinService;

    public function __construct(MoyenneService $moyenneService, BulletinService $bulletinService)
    {
        $this->moyenneService = $moyenneService;
        $this->bulletinService = $bulletinService;
    }

    public function index(Request $request)
    {
        try {
            $notes = Note::with(['etudiant', 'controle.sequence.unite'])
                ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
                ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
                ->get();
            return response()->json(['success' => true, 'data' => $notes]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des notes'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate(['valeur' => 'required|numeric|min:0|max:20']);
            $note = Note::with(['controle.sequence.unite', 'etudiant'])->findOrFail($id);

            $groupeId = $note->etudiant->groupe_id;
            $semestre = $note->controle->sequence->unite->semestre;
            $anneeAcademiqueId = $note->controle->sequence->annee_academique_id;

            $types = $semestre === 1 ? ['notes_s1', 'bulletin'] : ['notes_s2', 'bulletin'];
            $published = SemestrePublication::where('groupe_id', $groupeId)
                ->where('annee_academique_id', $anneeAcademiqueId)
                ->whereIn('type', $types)
                ->where('is_published', true)
                ->exists();

            if ($published) {
                return response()->json(['success' => false, 'message' => 'Impossible de modifier une note publiée'], 403);
            }

            $note->update(['valeur' => $request->valeur]);
            return response()->json(['success' => true, 'data' => $note, 'message' => 'Note mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la note'], 500);
        }
    }

    public function recapNotes(Request $request)
    {
        try {
            $request->validate([
                'groupe_id' => 'required|exists:groupes,id',
                'type' => 'required|string',
            ]);

            $groupe = \App\Models\Groupe::with('niveau.filiere')->findOrFail($request->groupe_id);
            $filiereId = $groupe->niveau->filiere_id;
            $niveauNumero = $groupe->niveau->numero;

            $semestre = null;
            $controleType = null;
            if (preg_match('/^(mpcc|mpefcf|mpefcfp)(\d)$/', $request->type, $m)) {
                $semestre = (int) $m[2];
                if ($m[1] === 'mpcc') $controleType = 'cc';
                elseif ($m[1] === 'mpefcf') $controleType = 'theorique';
                elseif ($m[1] === 'mpefcfp') $controleType = 'pratique';
            } elseif ($request->type === 'mpcc_global') {
                $controleType = 'cc';
            }

            $unites = Unite::where('filiere_id', $filiereId)
                ->where('numero_annee', $niveauNumero)
                ->where('is_active', true)
                ->when($semestre, fn($q) => $q->where('semestre', $semestre))
                ->with('sequences.controles')
                ->orderBy('ordre')
                ->get();

            $etudiants = Etudiant::where('groupe_id', $request->groupe_id)
                ->orderBy('nom_prenom')
                ->get();

            $subjects = [];
            foreach ($unites as $unite) {
                foreach ($unite->sequences as $seq) {
                    $subjects[] = [
                        'id' => $seq->id,
                        'nom' => $seq->nom . ' (' . $unite->nom . ')',
                        'coefficient' => $seq->coefficient,
                    ];
                }
            }

            $students = [];
            foreach ($etudiants as $etudiant) {
                $notes = [];
                $totalNote = 0;
                $totalCoef = 0;

                foreach ($subjects as $subject) {
                    $avg = $this->moyenneService->moyenneSequence($etudiant->id, $subject['id'], $controleType);
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

            return response()->json(['success' => true, 'data' => [
                'students' => $students,
                'subjects' => $subjects,
                'class_average' => [
                    'subjects' => $classSubjects,
                    'overall' => $classOverall,
                ],
                'header' => [
                    'filiere' => $groupe->niveau->filiere->nom ?? '',
                    'section' => $groupe->nom ?? '',
                    'annee_scolaire' => $groupe->annee_academique_id ? \App\Models\AnneeAcademique::find($groupe->annee_academique_id)?->label : '',
                    'annee_formation' => '',
                ],
            ]]);
        } catch (\Exception $e) {
            \Log::error('NoteAdminController::recapNotes error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement du relevé'], 500);
        }
    }

    public function bulletin(Request $request)
    {
        try {
            $request->validate([
                'etudiant_id' => 'required|exists:etudiants,id',
                'groupe_id' => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'semestre' => 'nullable|integer|in:1,2',
            ]);

            $bulletin = $this->bulletinService->calculateBulletin(
                $request->etudiant_id,
                $request->annee_academique_id,
                $request->semestre
            );

            $mention = $this->bulletinService->getMention($bulletin['moyenne_generale']);

            $semesters = [1 => ['unites' => []], 2 => ['unites' => []]];
            foreach ($bulletin['unites'] as $u) {
                $semesters[$u['semestre']]['unites'][] = $u;
            }

            return response()->json(['success' => true, 'data' => [
                'student' => $bulletin['student'],
                'semesters' => $semesters,
                'moyenne_generale' => $bulletin['moyenne_generale'],
                'moyenne_cc' => $bulletin['moyenne_cc'],
                'moyenne_theorique' => $bulletin['moyenne_theorique'],
                'moyenne_pratique' => $bulletin['moyenne_pratique'],
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
}
