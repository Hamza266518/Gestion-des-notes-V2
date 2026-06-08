<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Models\Controle;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Services\BulletinService;
use App\Services\MoyenneService;
use Illuminate\Http\Request;

class DiplomeController extends Controller
{
    protected $moyenneService;
    protected $bulletinService;

    public function __construct(MoyenneService $moyenneService, BulletinService $bulletinService)
    {
        $this->moyenneService = $moyenneService;
        $this->bulletinService = $bulletinService;
    }

    private function checkBulletinPublished($etudiant, $anneeAcademiqueId)
    {
        $groupeId = $etudiant->groupe_id;
        if (!$groupeId) return false;

        return SemestrePublication::where('groupe_id', $groupeId)
            ->where('annee_academique_id', $anneeAcademiqueId)
            ->where('type', 'bulletin')
            ->where('is_published', true)
            ->exists();
    }

    private function checkAllNotesFilled($etudiant, $anneeAcademiqueId)
    {
        $filiereId = $etudiant->groupe?->niveau?->filiere_id;
        if (!$filiereId) return false;

        $controleIds = Controle::whereHas('sequence', function ($q) use ($filiereId, $etudiant) {
            $q->whereHas('unite', function ($q2) use ($filiereId, $etudiant) {
                $q2->where('filiere_id', $filiereId)
                   ->where('numero_annee', $etudiant->groupe->niveau->numero);
            });
        })->pluck('id');

        if ($controleIds->isEmpty()) return true; // no controles = nothing to fill

        $notesCount = Note::where('etudiant_id', $etudiant->id)
            ->whereIn('controle_id', $controleIds)
            ->whereNotNull('valeur')
            ->count();

        return $notesCount >= $controleIds->count();
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

    public function download($id)
    {
        try {
            $diplome = Diplome::with([
                'etudiant.groupe.niveau.filiere',
                'anneeAcademique'
            ])->findOrFail($id);

            $etudiant = $diplome->etudiant;
            $filiere  = $etudiant->groupe?->niveau?->filiere;
            $groupe   = $etudiant->groupe;

            return response()->json([
                'success' => true,
                'data' => [
                    'nom_prenom'         => $etudiant->nom_prenom,
                    'type_formation'     => $filiere?->type_formation ?? '',
                    'date_naissance'     => $etudiant->date_naissance
                        ? \Carbon\Carbon::parse($etudiant->date_naissance)->format('d/m/Y') : '',
                    'lieu_naissance'     => $etudiant->lieu_naissance ?? '',
                    'cin'                => $etudiant->cin ?? '',
                    'nationalite'        => $etudiant->nationalite ?? 'Marocaine',
                    'numero_inscription' => $etudiant->numero_inscription ?? '',
                    'date_inscription'   => $etudiant->date_inscription
                        ? \Carbon\Carbon::parse($etudiant->date_inscription)->format('d/m/Y') : '',
                    'filiere'            => $filiere?->nom ?? '',
                    'promotion'          => $groupe?->promotion ?? '',
                    'moyenne'            => number_format($diplome->moyenne_generale, 2),
                    'mention'            => $diplome->mention,
                    'date_delivrance'    => now()->format('d/m/Y'),
                    // Arabic fields
                    'nom_ar'              => $etudiant->nom_ar ?? '',
                    'date_naissance_ar'     => $etudiant->date_naissance_ar
                        ?? ($etudiant->date_naissance
                            ? \Carbon\Carbon::parse($etudiant->date_naissance)->format('d/m/Y') : ''),
                    'lieu_naissance_ar'     => $etudiant->lieu_naissance_ar
                        ?? ($etudiant->lieu_naissance ?? ''),
                    'cin_ar'                => $etudiant->cin_ar
                        ?? ($etudiant->cin ?? ''),
                    'nationalite_ar'        => $etudiant->nationalite_ar
                        ?? ($etudiant->nationalite ?? 'Marocaine'),
                    'numero_inscription_ar' => $etudiant->numero_inscription_ar
                        ?? ($etudiant->numero_inscription ?? ''),
                    'date_inscription_ar'   => $etudiant->date_inscription_ar
                        ?? ($etudiant->date_inscription
                            ? \Carbon\Carbon::parse($etudiant->date_inscription)->format('d/m/Y') : ''),
                    'filiere_ar'            => $filiere?->nom_ar ?? '',
                    'type_formation_ar'     => $filiere?->type_formation_ar ?? '',
                ]
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
                } catch (\Exception $e) {
                    $skipped++;
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'created' => $created,
                    'skipped' => $skipped,
                    'total'   => $etudiants->count(),
                ],
                'message' => "{$created} diplôme(s) généré(s), {$skipped} non-admis/non-diplômables"
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
