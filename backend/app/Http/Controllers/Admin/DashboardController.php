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

        $filieres = Filiere::all();

        $data = $filieres->map(function ($filiere) use ($anneeId) {
            $niveauIds = Niveau::where('filiere_id', $filiere->id)->pluck('id');
            $groupeIds = Groupe::whereIn('niveau_id', $niveauIds)
                ->where('annee_academique_id', $anneeId)
                ->pluck('id');

            // Student stats
            $students = Etudiant::whereIn('groupe_id', $groupeIds)
                ->where('annee_academique_id', $anneeId)
                ->get();

            // Controle stats
            $uniteIds = Unite::where('filiere_id', $filiere->id)->pluck('id');
            $sequenceIds = Sequence::whereIn('unite_id', $uniteIds)->pluck('id');
            $allControles = Controle::whereIn('sequence_id', $sequenceIds)->get();
            $totalControles = $allControles->count();

            // Categorize controles by whether they have any notes
            $avecNotes = 0;
            $sansNotes = 0;

            foreach ($allControles as $ctrl) {
                $noteCount = Note::where('controle_id', $ctrl->id)->count();
                if ($noteCount > 0) {
                    $avecNotes++;
                } else {
                    $sansNotes++;
                }
            }

            // Group breakdown
            $groupes = Groupe::whereIn('niveau_id', $niveauIds)
                ->where('annee_academique_id', $anneeId)
                ->withCount(['etudiants' => function ($q) use ($anneeId) {
                    $q->where('annee_academique_id', $anneeId);
                }])
                ->get()
                ->map(function ($groupe) use ($allControles) {
                    $totalCtrl = $allControles->count();
                    $avecNotes = 0;
                    $sansNotes = 0;

                    foreach ($allControles as $ctrl) {
                        $noteCount = Note::where('controle_id', $ctrl->id)
                            ->whereHas('etudiant', function ($q) use ($groupe) {
                                $q->where('groupe_id', $groupe->id);
                            })
                            ->count();
                        if ($noteCount > 0) {
                            $avecNotes++;
                        } else {
                            $sansNotes++;
                        }
                    }

                    return [
                        'id' => $groupe->id,
                        'nom' => $groupe->nom,
                        'etudiants_count' => $groupe->etudiants_count,
                        'total_controles' => $totalCtrl,
                        'avec_notes' => $avecNotes,
                        'sans_notes' => $sansNotes,
                    ];
                });

            return [
                'filiere_id' => $filiere->id,
                'filiere_nom' => $filiere->nom,
                'total_students' => $students->count(),
                'active_students' => $students->where('status', 'active')->count(),
                'graduate_students' => $students->where('status', 'graduate')->count(),
                'dropped_students' => $students->where('status', 'dropped_out')->count(),
                'total_controles' => $totalControles,
                'avec_notes' => $avecNotes,
                'sans_notes' => $sansNotes,
                'saisie_pct' => $totalControles > 0 ? round(($avecNotes / $totalControles) * 100) : 0,
                'groupes' => $groupes,
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
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
            $groupeIds = Groupe::whereIn('niveau_id', $niveauIds)
                ->where('annee_academique_id', $anneeId)
                ->pluck('id');

            $unites = Unite::where('filiere_id', $filiere->id)->with(['sequences.controles' => function ($q) {
                $q->withCount(['notes']);
            }])->get();

            $pendingItems = [];
            $totalMissing = 0;

            foreach ($unites as $unite) {
                foreach ($unite->sequences as $seq) {
                    foreach ($seq->controles as $ctrl) {
                        $expectedCount = Etudiant::whereIn('groupe_id', $groupeIds)
                            ->where('annee_academique_id', $anneeId)
                            ->whereIn('status', ['active', 'graduate'])
                            ->count();

                        $notesEntered = $ctrl->notes_count;
                        $missing = max(0, $expectedCount - $notesEntered);

                        if ($missing > 0 || $notesEntered === 0) {
                            $missingStudents = [];
                            if ($notesEntered < $expectedCount) {
                                $studentIdsWithNotes = Note::where('controle_id', $ctrl->id)
                                    ->pluck('etudiant_id')
                                    ->toArray();
                                $missingStudents = Etudiant::whereIn('groupe_id', $groupeIds)
                                    ->where('annee_academique_id', $anneeId)
                                    ->whereIn('status', ['active', 'graduate'])
                                    ->whereNotIn('id', $studentIdsWithNotes)
                                    ->take(5)
                                    ->get(['id', 'nom_prenom', 'cin'])
                                    ->toArray();
                            }

                            $pendingItems[] = [
                                'unite_nom' => $unite->nom,
                                'sequence_nom' => $seq->nom,
                                'controle_id' => $ctrl->id,
                                'controle_numero' => $ctrl->numero,
                                'total_expected' => $expectedCount,
                                'notes_entered' => $notesEntered,
                                'missing_count' => $missing,
                                'missing_students' => $missingStudents,
                            ];
                            $totalMissing += $missing;
                        }
                    }
                }
            }

            usort($pendingItems, function ($a, $b) {
                return $b['missing_count'] - $a['missing_count'];
            });

            $totalPending = count($pendingItems);

            return [
                'filiere_id' => $filiere->id,
                'filiere_nom' => $filiere->nom,
                'total_pending_controles' => $totalPending,
                'total_missing_notes' => $totalMissing,
                'items' => array_slice($pendingItems, 0, $limit),
            ];
        });

        return response()->json(['success' => true, 'data' => $data]);
    }
}
