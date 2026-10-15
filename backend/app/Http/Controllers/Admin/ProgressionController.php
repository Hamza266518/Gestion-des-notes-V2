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

    /**
     * Pick the group a student lands in for the new year.
     *
     * Returns the Groupe, null when there is no candidate at all, or a Collection of
     * candidates when the choice is genuinely ambiguous -- a niveau with several
     * groups is a real situation, and silently taking first() assigned students to an
     * arbitrary one. Callers surface that ambiguity as an error so the admin can name
     * the destination via `groupe_ids`.
     */
    private function resolveTargetGroup($toAnneeId, $niveauId, $explicit, string $nomPrenom)
    {
        if ($explicit) {
            $group = Groupe::find($explicit);
            if ($group && (int) $group->annee_academique_id === (int) $toAnneeId
                && (int) $group->niveau_id === (int) $niveauId) {
                return $group;
            }

            return null;
        }

        $candidates = Groupe::where('annee_academique_id', $toAnneeId)
            ->where('niveau_id', $niveauId)
            ->orderBy('id')
            ->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates;
    }

    public function confirmRedoublants(Request $request): JsonResponse
    {
        $request->validate([
            'etudiant_ids' => 'required|array',
            'etudiant_ids.*' => 'exists:etudiants,id',
            'to_annee_id' => 'required|exists:annees_academiques,id',
            // Optional per-student destination, keyed by student id. Without it the
            // target group was picked with first(), so a niveau running Groupe A and
            // Groupe B was split arbitrarily and the admin had no say.
            'groupe_ids' => 'nullable|array',
            'groupe_ids.*' => 'exists:groupes,id',
        ]);

        $groupeIds = (array) ($request->groupe_ids ?? []);
        $confirmed = 0;
        $errors = [];
        $ambiguous = [];

        foreach ($request->etudiant_ids as $id) {
            try {
                $student = Etudiant::with('groupe.niveau')->findOrFail($id);
                $niveauId = $student->groupe?->niveau_id;

                if (!$niveauId) {
                    $errors[] = "Étudiant {$student->nom_prenom}: pas de niveau trouvé";
                    continue;
                }

                $targetGroup = $this->resolveTargetGroup(
                    $request->to_annee_id,
                    $niveauId,
                    $groupeIds[$id] ?? null,
                    $student->nom_prenom
                );

                if ($targetGroup instanceof \Illuminate\Support\Collection) {
                    // Ambiguous: report the choices so the admin can pick. The
                    // structured list lets the caller offer a selector instead of
                    // asking them to hand-edit groupe_ids.
                    $choices = $targetGroup->pluck('nom')->implode(', ');
                    $errors[] = "Étudiant {$student->nom_prenom}: plusieurs groupes pour ce niveau ({$choices}). Précisez groupe_ids[{$id}].";
                    $ambiguous[] = [
                        'etudiant_id' => $student->id,
                        'nom_prenom' => $student->nom_prenom,
                        'niveau_id' => $niveauId,
                        'groupes' => $targetGroup->map(fn($g) => [
                            'id' => $g->id,
                            'nom' => $g->nom,
                        ])->values(),
                    ];
                    continue;
                }

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
            'ambiguous' => $ambiguous,
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
            'groupe_ids' => 'nullable|array',
            'groupe_ids.*' => 'exists:groupes,id',
        ]);

        $students = Etudiant::where('annee_academique_id', $request->from_annee_id)
            ->where('status', 'active')
            ->with('groupe.niveau.filiere')
            ->get();

        $groupeIds = (array) ($request->groupe_ids ?? []);
        $promoted = 0;
        $graduated = 0;
        $errors = [];
        $ambiguous = [];

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

                $targetGroup = $this->resolveTargetGroup(
                    $request->to_annee_id,
                    $nextNiveau->id,
                    $groupeIds[$student->id] ?? null,
                    $student->nom_prenom
                );

                if ($targetGroup instanceof \Illuminate\Support\Collection) {
                    $choices = $targetGroup->pluck('nom')->implode(', ');
                    $errors[] = "{$student->nom_prenom}: plusieurs groupes pour le niveau {$nextNiveau->numero} ({$choices}). Précisez groupe_ids[{$student->id}].";
                    $ambiguous[] = [
                        'etudiant_id' => $student->id,
                        'nom_prenom' => $student->nom_prenom,
                        'niveau_id' => $nextNiveau->id,
                        'niveau_numero' => $nextNiveau->numero,
                        'groupes' => $targetGroup->map(fn($g) => [
                            'id' => $g->id,
                            'nom' => $g->nom,
                        ])->values(),
                    ];
                    continue;
                }

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
            'ambiguous' => $ambiguous,
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

            // Every branch below either derives the promotion or fails. The previous
            // version fell back to $group->promotion -- the *outgoing* year's string --
            // whenever the previous level could not be found, which printed e.g.
            // "2023/2026" on a 2025/2026 group.
            $promotion = null;

            if ($group->niveau && $group->niveau->numero === 1) {
                $filiere = $group->niveau->filiere;
                $duree = $filiere?->nombre_annees;

                // ?? 1 silently stamped "2025/2026" on a three-year programme.
                // Better to refuse and let the admin fix the filiere.
                if (!$duree || (int) $duree < 1) {
                    $errors[] = "Groupe {$group->nom}: la filière {$filiere?->nom} n'a pas de nombre_annees exploitable, promotion indéterminée";
                    continue;
                }

                $gradYear = (int) $newYearStart + (int) $duree;
                $promotion = $newYearStart . '/' . $gradYear;
            } elseif ($group->niveau && $group->niveau->numero > 1) {
                $prevNiveau = Niveau::where('filiere_id', $group->niveau->filiere_id)
                    ->where('numero', $group->niveau->numero - 1)
                    ->first();

                if (!$prevNiveau) {
                    $errors[] = "Groupe {$group->nom}: niveau {$group->niveau->numero} sans niveau précédent dans la filière, promotion indéterminée";
                    continue;
                }

                $prevGroup = Groupe::where('annee_academique_id', $request->from_annee_id)
                    ->where('niveau_id', $prevNiveau->id)
                    ->first();

                if (!$prevGroup) {
                    $errors[] = "Groupe {$group->nom}: aucun groupe de niveau {$prevNiveau->numero} dans l'année {$toAnnee->label}, promotion indéterminée";
                    continue;
                }

                $promotion = $prevGroup->promotion;
            } else {
                $errors[] = "Groupe {$group->nom}: sans niveau, promotion indéterminée";
                continue;
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
