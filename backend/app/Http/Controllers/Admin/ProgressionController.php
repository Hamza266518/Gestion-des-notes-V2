<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Services\BulletinService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ProgressionController extends Controller
{
    protected $bulletinService;

    public function __construct(BulletinService $bulletinService)
    {
        $this->bulletinService = $bulletinService;
    }

    public function checkRedoublants(Request $request): JsonResponse
    {
        $request->validate([
            'from_annee_id' => 'required|exists:annees_academiques,id',
        ]);

        $students = Etudiant::where('annee_academique_id', $request->from_annee_id)
            ->where('status', 'active')
            ->with('groupe.niveau.filiere')
            ->get();

        $redoublants = [];

        foreach ($students as $student) {
            try {
                $bulletin = $this->bulletinService->calculateBulletin(
                    $student->id,
                    $request->from_annee_id,
                    null
                );

                if (($bulletin['decision'] ?? '') === 'Redoublant(e)') {
                    $redoublants[] = [
                        'id' => $student->id,
                        'nom_prenom' => $student->nom_prenom,
                        'numero_inscription' => $student->numero_inscription,
                        'niveau' => $student->groupe?->niveau?->numero,
                        'filiere' => $student->groupe?->niveau?->filiere?->nom,
                        'moyenne' => $bulletin['moyenne_generale'],
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $redoublants,
            'count' => count($redoublants),
        ]);
    }

    public function confirmRedoublants(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_ids' => 'required|array',
            'etudiant_ids.*' => 'exists:etudiants,id',
            'to_annee_id' => 'required|exists:annees_academiques,id',
        ]);

        $confirmed = 0;
        $errors = [];

        foreach ($request->etudiant_ids as $id) {
            try {
                $student = Etudiant::with('groupe.niveau')->findOrFail($id);
                $niveauId = $student->groupe?->niveau_id;

                if (!$niveauId) {
                    $errors[] = "Étudiant {$student->nom_prenom}: pas de niveau trouvé";
                    continue;
                }

                $targetGroup = Groupe::where('annee_academique_id', $request->to_annee_id)
                    ->where('niveau_id', $niveauId)
                    ->first();

                if (!$targetGroup) {
                    $errors[] = "Étudiant {$student->nom_prenom}: aucun groupe trouvé pour le niveau dans la nouvelle année";
                    continue;
                }

                $student->update([
                    'groupe_id' => $targetGroup->id,
                    'annee_academique_id' => $request->to_annee_id,
                    'status' => 'active',
                ]);

                $confirmed++;
            } catch (\Exception $e) {
                $errors[] = "Étudiant ID {$id}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$confirmed} redoublant(s) confirmé(s)",
            'confirmed' => $confirmed,
            'errors' => $errors,
        ]);
    }

    public function dropRedoublants(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_ids' => 'required|array',
            'etudiant_ids.*' => 'exists:etudiants,id',
        ]);

        $dropped = 0;

        foreach ($request->etudiant_ids as $id) {
            Etudiant::where('id', $id)->update(['status' => 'dropped_out']);
            $dropped++;
        }

        return response()->json([
            'success' => true,
            'message' => "{$dropped} étudiant(s) retiré(s)",
            'dropped' => $dropped,
        ]);
    }

    public function promoteAdmis(Request $request): JsonResponse
    {
        $request->validate([
            'from_annee_id' => 'required|exists:annees_academiques,id',
            'to_annee_id' => 'required|exists:annees_academiques,id',
        ]);

        $students = Etudiant::where('annee_academique_id', $request->from_annee_id)
            ->where('status', 'active')
            ->with('groupe.niveau.filiere')
            ->get();

        $promoted = 0;
        $graduated = 0;
        $errors = [];

        foreach ($students as $student) {
            try {
                $bulletin = $this->bulletinService->calculateBulletin(
                    $student->id,
                    $request->from_annee_id,
                    null
                );

                if (($bulletin['decision'] ?? '') !== 'Admis(e)') continue;

                $currentNiveau = $student->groupe?->niveau;
                if (!$currentNiveau) {
                    $errors[] = "{$student->nom_prenom}: pas de niveau";
                    continue;
                }

                $nextNiveau = Niveau::where('filiere_id', $currentNiveau->filiere_id)
                    ->where('numero', $currentNiveau->numero + 1)
                    ->first();

                if (!$nextNiveau) {
                    $student->update(['status' => 'graduate']);
                    $graduated++;
                    continue;
                }

                $targetGroup = Groupe::where('annee_academique_id', $request->to_annee_id)
                    ->where('niveau_id', $nextNiveau->id)
                    ->first();

                if (!$targetGroup) {
                    $errors[] = "{$student->nom_prenom}: aucun groupe pour le niveau {$nextNiveau->numero} dans la nouvelle année";
                    continue;
                }

                $student->update([
                    'groupe_id' => $targetGroup->id,
                    'annee_academique_id' => $request->to_annee_id,
                    'status' => 'active',
                ]);

                $promoted++;
            } catch (\Exception $e) {
                $errors[] = "{$student->nom_prenom}: {$e->getMessage()}";
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$promoted} promu(s), {$graduated} diplômé(s)",
            'promoted' => $promoted,
            'graduated' => $graduated,
            'errors' => $errors,
        ]);
    }

    public function copyGroups(Request $request): JsonResponse
    {
        $request->validate([
            'from_annee_id' => 'required|exists:annees_academiques,id',
            'to_annee_id' => 'required|exists:annees_academiques,id',
        ]);

        $oldGroups = Groupe::where('annee_academique_id', $request->from_annee_id)
            ->with('niveau.filiere')
            ->get();

        $toAnnee = AnneeAcademique::findOrFail($request->to_annee_id);
        $parts = explode('/', $toAnnee->label);
        $newYearStart = $parts[0] ?? '';

        $created = 0;
        $errors = [];

        foreach ($oldGroups as $group) {
            $exists = Groupe::where('annee_academique_id', $request->to_annee_id)
                ->where('niveau_id', $group->niveau_id)
                ->where('nom', $group->nom)
                ->exists();

            if ($exists) continue;

            $promotion = $group->promotion;

            if ($group->niveau && $group->niveau->numero === 1) {
                $duree = $group->niveau->filiere->nombre_annees ?? 1;
                $gradYear = (int)$newYearStart + (int)$duree;
                $promotion = $newYearStart . '/' . $gradYear;
            } elseif ($group->niveau && $group->niveau->numero > 1) {
                $prevNiveau = Niveau::where('filiere_id', $group->niveau->filiere_id)
                    ->where('numero', $group->niveau->numero - 1)
                    ->first();
                if ($prevNiveau) {
                    $prevGroup = Groupe::where('annee_academique_id', $request->from_annee_id)
                        ->where('niveau_id', $prevNiveau->id)
                        ->first();
                    if ($prevGroup) {
                        $promotion = $prevGroup->promotion;
                    }
                }
            }

            try {
                Groupe::create([
                    'niveau_id' => $group->niveau_id,
                    'annee_academique_id' => $request->to_annee_id,
                    'nom' => $group->nom,
                    'promotion' => $promotion,
                ]);
                $created++;
            } catch (\Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        return response()->json([
            'success' => true,
            'message' => "{$created} groupe(s) créé(s)",
            'created' => $created,
            'errors' => $errors,
        ]);
    }
}
