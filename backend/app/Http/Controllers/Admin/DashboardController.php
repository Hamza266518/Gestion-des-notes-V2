<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filiere;
use App\Models\Unite;
use App\Models\Sequence;
use App\Models\Controle;
use App\Models\Note;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Diplome;
use App\Models\SemestrePublication;
use App\Models\Formateur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $anneeId = $request->input('annee_academique_id');
        if (!$anneeId) {
            return response()->json(['success' => false, 'message' => 'annee_academique_id required'], 400);
        }

        // Year-wide published records (groupe_id => types), all groups of the year
        $publishedRows = SemestrePublication::where('annee_academique_id', $anneeId)
            ->where('is_published', true)
            ->get(['groupe_id', 'type']);

        $filieres = Filiere::all();
        $totalFormateurs = Formateur::count();

        $overview = [
            'total_students' => 0,
            'active_students' => 0,
            'graduate_students' => 0,
            'dropped_students' => 0,
            'total_groupes' => 0,
            'total_niveaux' => 0,
            'total_unites' => 0,
            'total_sequences' => 0,
            'total_controles' => 0,
            'controles_avec_notes' => 0,
            'controles_sans_notes' => 0,
            'notes_entered' => 0,
            'notes_expected' => 0,
            'notes_missing' => 0,
            'notes_pct' => 0,
            'saisie_pct' => 0,
            'total_diplomes' => 0,
            'diplomes_printed' => 0,
            'groupes_publies' => 0,
            'publications_by_type' => [
                'notes_s1' => 0,
                'notes_s2' => 0,
                'bulletin' => 0,
            ],
            'total_formateurs' => $totalFormateurs,
        ];

        $filieresData = $filieres->map(function ($filiere) use ($anneeId, $publishedRows, &$overview) {
            $niveauIds = Niveau::where('filiere_id', $filiere->id)->pluck('id');
            $groupeIds = Groupe::whereIn('niveau_id', $niveauIds)
                ->where('annee_academique_id', $anneeId)
                ->pluck('id');

            // Students
            $students = Etudiant::whereIn('groupe_id', $groupeIds)
                ->where('annee_academique_id', $anneeId)
                ->get();
            $activeStudents = $students->where('status', 'active')->count();
            $graduateStudents = $students->where('status', 'graduate')->count();
            $droppedStudents = $students->where('status', 'dropped_out')->count();
            $expectedStudents = $activeStudents + $graduateStudents;

            // Unites / sequences / controles
            $uniteIds = Unite::where('filiere_id', $filiere->id)->pluck('id');
            $sequenceIds = Sequence::whereIn('unite_id', $uniteIds)->pluck('id');
            $allControles = Controle::whereIn('sequence_id', $sequenceIds)
                ->with('sequence.unite')
                ->get();
            $totalControles = $allControles->count();

            // Expected students per academic year (niveau.numero), only active/graduate
            $expectedByNiveau = [];
            $gIdsByNiveau = [];
            foreach (Niveau::whereIn('id', $niveauIds)->get() as $niveau) {
                $gIds = Groupe::where('niveau_id', $niveau->id)
                    ->where('annee_academique_id', $anneeId)
                    ->pluck('id');
                $gIdsByNiveau[$niveau->numero] = $gIds->toArray();
                $expectedByNiveau[$niveau->numero] = Etudiant::whereIn('groupe_id', $gIds)
                    ->where('annee_academique_id', $anneeId)
                    ->whereIn('status', ['active', 'graduate'])
                    ->count();
            }

            // Notes entered by this year's students, one query per academic year
            // (a grade only counts towards the year it belongs to)
            $enteredByCtrl = [];
            foreach ($allControles->groupBy(fn ($c) => $c->sequence?->unite?->numero_annee) as $numero => $ctrlsOfNiveau) {
                $niveauGroupeIds = $gIdsByNiveau[$numero] ?? [];
                if (!$niveauGroupeIds) {
                    continue;
                }
                $map = Note::whereIn('controle_id', $ctrlsOfNiveau->pluck('id'))
                    ->whereHas('etudiant', function ($q) use ($niveauGroupeIds, $anneeId) {
                        $q->whereIn('groupe_id', $niveauGroupeIds)
                            ->where('annee_academique_id', $anneeId)
                            ->whereIn('status', ['active', 'graduate']);
                    })
                    ->groupBy('controle_id')
                    ->selectRaw('controle_id, COUNT(*) as total')
                    ->pluck('total', 'controle_id');
                foreach ($map as $ctrlId => $count) {
                    $enteredByCtrl[$ctrlId] = (int) $count;
                }
            }

            $notesEntered = 0;
            $notesExpected = 0;
            $notesMissing = 0;
            $avecNotes = 0;
            foreach ($allControles as $ctrl) {
                $exp = $expectedByNiveau[$ctrl->sequence?->unite?->numero_annee] ?? 0;
                $entered = $enteredByCtrl[$ctrl->id] ?? 0;
                $notesExpected += $exp;
                $notesEntered += $entered;
                $notesMissing += max(0, $exp - $entered);
                if ($entered > 0) {
                    $avecNotes++;
                }
            }
            $sansNotes = $totalControles - $avecNotes;
            $notesPct = $notesExpected > 0 ? round(($notesEntered / $notesExpected) * 100, 1) : 0;

            // Groups: controle progress + publication status
            $groupes = Groupe::whereIn('niveau_id', $niveauIds)
                ->where('annee_academique_id', $anneeId)
                ->with('niveau')
                ->withCount([
                    'etudiants as etudiants_count' => function ($q) use ($anneeId) {
                        $q->where('annee_academique_id', $anneeId);
                    },
                    'etudiants as expected_count' => function ($q) use ($anneeId) {
                        $q->where('annee_academique_id', $anneeId)
                            ->whereIn('status', ['active', 'graduate']);
                    },
                ])
                ->get()
                ->map(function ($groupe) use ($allControles, $publishedRows, $anneeId) {
                    // Students only take the controles of their own year's unités
                    $niveauNumero = $groupe->niveau?->numero;
                    $gControles = $allControles
                        ->filter(fn ($c) => $c->sequence?->unite?->numero_annee == $niveauNumero)
                        ->values();

                    $gEnteredByCtrl = Note::whereIn('controle_id', $gControles->pluck('id'))
                        ->whereHas('etudiant', function ($q) use ($groupe, $anneeId) {
                            $q->where('groupe_id', $groupe->id)
                                ->where('annee_academique_id', $anneeId)
                                ->whereIn('status', ['active', 'graduate']);
                        })
                        ->groupBy('controle_id')
                        ->selectRaw('controle_id, COUNT(*) as total')
                        ->pluck('total', 'controle_id');

                    $gNotesEntered = 0;
                    $gExpected = 0;
                    $gMissing = 0;
                    $gCtrlAvec = 0;
                    $expectedPerStudent = (int) ($groupe->expected_count ?? 0);
                    foreach ($gControles as $ctrl) {
                        $entered = (int) ($gEnteredByCtrl[$ctrl->id] ?? 0);
                        $gNotesEntered += $entered;
                        $gExpected += $expectedPerStudent;
                        $gMissing += max(0, $expectedPerStudent - $entered);
                        if ($entered > 0) {
                            $gCtrlAvec++;
                        }
                    }

                    $pubTypes = $publishedRows
                        ->where('groupe_id', $groupe->id)
                        ->pluck('type')
                        ->values()
                        ->all();

                    return [
                        'id' => $groupe->id,
                        'nom' => $groupe->nom,
                        'niveau_numero' => $groupe->niveau?->numero,
                        'etudiants_count' => $groupe->etudiants_count,
                        'expected_count' => (int) ($groupe->expected_count ?? 0),
                        'total_controles' => $gControles->count(),
                        'avec_notes' => $gCtrlAvec,
                        'sans_notes' => $gControles->count() - $gCtrlAvec,
                        'notes_entered' => $gNotesEntered,
                        'notes_expected' => $gExpected,
                        'notes_missing' => $gMissing,
                        'notes_pct' => $gExpected > 0 ? round(($gNotesEntered / $gExpected) * 100, 1) : 0,
                        'publie_types' => $pubTypes,
                        'publie' => count($pubTypes) > 0,
                    ];
                });

            // Diplomes
            $diplomes = Diplome::where('annee_academique_id', $anneeId)
                ->whereHas('etudiant', function ($q) use ($groupeIds) {
                    $q->whereIn('groupe_id', $groupeIds);
                })
                ->get();
            $totalDiplomes = $diplomes->count();
            $printedDiplomes = $diplomes->where('is_printed', true)->count();

            // Publications per type (groups of this filiere)
            $filPub = ['notes_s1' => 0, 'notes_s2' => 0, 'bulletin' => 0];
            foreach ($publishedRows as $row) {
                if ($groupeIds->contains($row->groupe_id) && isset($filPub[$row->type])) {
                    $filPub[$row->type]++;
                }
            }
            $groupesPublies = $groupes->filter(fn ($g) => $g['publie'])->count();

            // Accumulate year overview
            $overview['total_students'] += $students->count();
            $overview['active_students'] += $activeStudents;
            $overview['graduate_students'] += $graduateStudents;
            $overview['dropped_students'] += $droppedStudents;
            $overview['total_groupes'] += $groupes->count();
            $overview['total_niveaux'] += count($niveauIds);
            $overview['total_unites'] += count($uniteIds);
            $overview['total_sequences'] += count($sequenceIds);
            $overview['total_controles'] += $totalControles;
            $overview['controles_avec_notes'] += $avecNotes;
            $overview['controles_sans_notes'] += $sansNotes;
            $overview['notes_entered'] += $notesEntered;
            $overview['notes_expected'] += $notesExpected;
            $overview['notes_missing'] += $notesMissing;
            $overview['total_diplomes'] += $totalDiplomes;
            $overview['diplomes_printed'] += $printedDiplomes;
            $overview['groupes_publies'] += $groupesPublies;
            $overview['publications_by_type']['notes_s1'] += $filPub['notes_s1'];
            $overview['publications_by_type']['notes_s2'] += $filPub['notes_s2'];
            $overview['publications_by_type']['bulletin'] += $filPub['bulletin'];

            return [
                'filiere_id' => $filiere->id,
                'filiere_nom' => $filiere->nom,
                'total_students' => $students->count(),
                'active_students' => $activeStudents,
                'graduate_students' => $graduateStudents,
                'dropped_students' => $droppedStudents,
                'expected_students' => $expectedStudents,
                'total_groupes' => $groupes->count(),
                'total_niveaux' => count($niveauIds),
                'total_unites' => count($uniteIds),
                'total_sequences' => count($sequenceIds),
                'total_controles' => $totalControles,
                'controles_avec_notes' => $avecNotes,
                'controles_sans_notes' => $sansNotes,
                'saisie_pct' => $totalControles > 0 ? round(($avecNotes / $totalControles) * 100) : 0,
                'notes_entered' => $notesEntered,
                'notes_expected' => $notesExpected,
                'notes_missing' => $notesMissing,
                'notes_pct' => $notesPct,
                'total_diplomes' => $totalDiplomes,
                'diplomes_printed' => $printedDiplomes,
                'diplomes_non_imprimes' => max(0, $totalDiplomes - $printedDiplomes),
                'groupes_publies' => $groupesPublies,
                'publications' => $filPub,
                'groupes' => $groupes,
            ];
        });

        $overview['notes_pct'] = $overview['notes_expected'] > 0
            ? round(($overview['notes_entered'] / $overview['notes_expected']) * 100, 1)
            : 0;
        $overview['saisie_pct'] = $overview['total_controles'] > 0
            ? round(($overview['controles_avec_notes'] / $overview['total_controles']) * 100)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'overview' => $overview,
                'filieres' => $filieresData,
            ],
        ]);
    }

    public function controlesPending(Request $request)
    {
        $anneeId = $request->input('annee_academique_id');
        if (!$anneeId) {
            return response()->json(['success' => false, 'message' => 'annee_academique_id required'], 400);
        }

        $filiereId = $request->input('filiere_id');
        $limit = (int) $request->input('limit', 10);

        $filieres = $filiereId ? Filiere::where('id', $filiereId)->get() : Filiere::all();

        $data = $filieres->map(function ($filiere) use ($anneeId, $limit) {
            $niveauIds = Niveau::where('filiere_id', $filiere->id)->pluck('id');

            // Per academic year (niveau.numero): groupes + expected students
            $gIdsByNiveau = [];
            $expectedByNiveau = [];
            foreach (Niveau::whereIn('id', $niveauIds)->get() as $niveau) {
                $nGroupeIds = Groupe::where('niveau_id', $niveau->id)
                    ->where('annee_academique_id', $anneeId)
                    ->pluck('id');
                $gIdsByNiveau[$niveau->numero] = $nGroupeIds->toArray();
                $expectedByNiveau[$niveau->numero] = Etudiant::whereIn('groupe_id', $nGroupeIds)
                    ->where('annee_academique_id', $anneeId)
                    ->whereIn('status', ['active', 'graduate'])
                    ->count();
            }

            $unites = Unite::where('filiere_id', $filiere->id)->with(['sequences.controles'])->get();

            $ctrlsByNiveau = [];
            foreach ($unites as $u) {
                foreach ($u->sequences as $seq) {
                    foreach ($seq->controles as $ctrl) {
                        $ctrlsByNiveau[$u->numero_annee][] = $ctrl->id;
                    }
                }
            }

            // Per academic year: student pool + ids of students who already have a
            // grade per controle (2 queries per year instead of 2 per controle)
            $poolByNiveau = [];
            $notedIdsByNiveau = [];
            foreach ($ctrlsByNiveau as $numero => $ctrlIds) {
                $niveauGroupeIds = $gIdsByNiveau[$numero] ?? [];
                $pool = $niveauGroupeIds
                    ? Etudiant::whereIn('groupe_id', $niveauGroupeIds)
                        ->where('annee_academique_id', $anneeId)
                        ->whereIn('status', ['active', 'graduate'])
                        ->get(['id', 'nom_prenom', 'cin'])
                        ->toArray()
                    : [];
                $poolByNiveau[$numero] = $pool;

                $noted = [];
                $poolIds = array_column($pool, 'id');
                if ($poolIds) {
                    $rows = Note::whereIn('controle_id', $ctrlIds)
                        ->whereIn('etudiant_id', $poolIds)
                        ->get(['controle_id', 'etudiant_id']);
                    foreach ($rows as $row) {
                        $noted[$row->controle_id][] = $row->etudiant_id;
                    }
                }
                $notedIdsByNiveau[$numero] = $noted;
            }

            $pendingItems = [];
            $totalMissing = 0;

            foreach ($unites as $unite) {
                foreach ($unite->sequences as $seq) {
                    foreach ($seq->controles as $ctrl) {
                        // A student only takes the controles of his own year's unités
                        $expectedCount = $expectedByNiveau[$unite->numero_annee] ?? 0;

                        $notesEntered = count($notedIdsByNiveau[$unite->numero_annee][$ctrl->id] ?? []);
                        $missing = max(0, $expectedCount - $notesEntered);

                        if ($missing > 0 || $notesEntered === 0) {
                            $pendingItems[] = [
                                'unite_nom' => $unite->nom,
                                'sequence_nom' => $seq->nom,
                                'controle_id' => $ctrl->id,
                                'controle_numero' => $ctrl->numero,
                                'niveau_numero' => $unite->numero_annee,
                                'total_expected' => $expectedCount,
                                'notes_entered' => $notesEntered,
                                'missing_count' => $missing,
                                'missing_students' => [],
                            ];
                            $totalMissing += $missing;
                        }
                    }
                }
            }

            usort($pendingItems, function ($a, $b) {
                return $b['missing_count'] - $a['missing_count'];
            });

            // Missing student names are only resolved for the rows actually returned
            $items = array_slice($pendingItems, 0, $limit);
            foreach ($items as &$item) {
                if ($item['missing_count'] > 0) {
                    $notedIds = $notedIdsByNiveau[$item['niveau_numero']][$item['controle_id']] ?? [];
                    $item['missing_students'] = collect($poolByNiveau[$item['niveau_numero']] ?? [])
                        ->reject(fn ($student) => in_array($student['id'], $notedIds))
                        ->take(5)
                        ->values()
                        ->toArray();
                }
            }
            unset($item);

            return [
                'filiere_id' => $filiere->id,
                'filiere_nom' => $filiere->nom,
                'total_pending_controles' => count($pendingItems),
                'total_missing_notes' => $totalMissing,
                'items' => $items,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
