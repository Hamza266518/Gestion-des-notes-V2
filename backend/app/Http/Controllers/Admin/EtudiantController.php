<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Niveau;
use App\Models\User;
use App\Services\EtudiantDocxService;
use App\Services\GeminiService;
use App\Services\NumeroInscriptionService;
use App\Services\UsernameGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EtudiantController extends Controller
{
    protected $numeroService;

    public function __construct(NumeroInscriptionService $numeroService)
    {
        $this->numeroService = $numeroService;
    }

    public function index(Request $request)
    {
        try {
            $etudiants = $this->studentsQuery($request)->orderBy('nom_prenom')->get();

            // Admin-only: explicitly reveal the current password for the student list
            $etudiants->each(fn (Etudiant $e) => $e->user?->withPlainPassword());

            return response()->json(['success' => true, 'data' => $etudiants]);
        } catch (\Exception $e) {
            \Log::error('EtudiantController::index error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des étudiants'], 500);
        }
    }

    /**
     * The student set the list page reports on, shared by index() and the two
     * .docx exports so a download always mirrors the filters on screen: the
     * académique year (current one by default), groupe, niveau, filière and the
     * free-text search.
     */
    private function studentsQuery(Request $request)
    {
        $query = Etudiant::with(['user', 'groupe.niveau.filiere', 'anneeAcademique']);

        $anneeId = $request->annee_academique_id;
        if (!$anneeId) {
            $anneeId = AnneeAcademique::where('is_current', true)->first()?->id;
        }
        if ($anneeId) {
            $query->where('annee_academique_id', $anneeId);
        }

        if ($request->filled('groupe_id')) {
            $query->where('groupe_id', $request->groupe_id);
        }

        if ($request->filled('niveau_id')) {
            $query->whereHas('groupe', fn($q) => $q->where('niveau_id', $request->niveau_id));
        }

        if ($request->filled('filiere_id')) {
            $query->whereHas('groupe.niveau', fn($q) => $q->where('filiere_id', $request->filiere_id));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nom_prenom', 'like', '%' . $search . '%')
                  ->orWhere('cin', 'like', '%' . $search . '%')
                  ->orWhere('numero_inscription', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    /**
     * The "Liste stagiaire" .docx: the students currently listed, grouped by
     * group so each identification block (filière / année scolaire / section /
     * année de formation / promotion) describes exactly the rows printed under
     * it, one group per page, matching the reference list file.
     */
    public function docxListe(Request $request)
    {
        try {
            $etudiants = $this->studentsQuery($request)->orderBy('nom_prenom')->get();

            if ($etudiants->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun étudiant ne correspond aux filtres sélectionnés',
                ], 422);
            }

            $service = app(EtudiantDocxService::class);
            $context = $this->docxContext($request, $etudiants);
            $path = $service->buildListe($this->groupDatasets($etudiants));

            return response()->download($path, $service->buildListeFilename($context), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('EtudiantController::docxListe error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération de la liste'], 500);
        }
    }

    /**
     * The "Identifiants et mots de passe" .docx: the one-page account list handed
     * out to the students (identifiant + mot de passe), in the list's own order
     * and covering the same filters.
     */
    public function docxIdentifiants(Request $request)
    {
        try {
            $etudiants = $this->studentsQuery($request)->orderBy('nom_prenom')->get();

            if ($etudiants->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun étudiant ne correspond aux filtres sélectionnés',
                ], 422);
            }

            $rows = $etudiants->map(fn (Etudiant $e) => [
                'nom_prenom'         => $e->nom_prenom,
                'numero_inscription' => $e->numero_inscription,
                'identifiant'        => $e->user?->username,
                'mot_de_passe'       => $e->user?->withPlainPassword()->password_plain,
            ])->all();

            $service = app(EtudiantDocxService::class);
            $context = $this->docxContext($request, $etudiants);
            $path = $service->buildIdentifiants(['header' => $context, 'rows' => $rows]);

            return response()->download($path, $service->buildIdentifiantsFilename($context), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            \Log::error('EtudiantController::docxIdentifiants error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la génération des identifiants'], 500);
        }
    }

    /**
     * One dataset per group: the group's identification data plus its students,
     * ordered by level then group name, students by N° d'inscription (the
     * reference sheet's own order).
     */
    private function groupDatasets($etudiants): array
    {
        $groups = [];

        foreach ($etudiants as $etudiant) {
            $groupe = $etudiant->groupe;
            $key = $groupe?->id ?? 0;

            if (!isset($groups[$key])) {
                $filiere = $groupe?->niveau?->filiere;
                $groups[$key] = [
                    'filiere'        => $filiere?->nom ?? '',
                    'section'        => $filiere?->section ?? '',
                    'annee_scolaire' => $etudiant->anneeAcademique?->label ?? '',
                    'niveau'         => $this->niveauLabel($groupe?->niveau?->numero),
                    'promotion'      => $groupe?->promotion ?? '',
                    'niveau_numero'  => (int) ($groupe?->niveau?->numero ?? 0),
                    'groupe_nom'     => (string) ($groupe?->nom ?? ''),
                    'etudiants'      => [],
                ];
            }

            $groups[$key]['etudiants'][] = [
                'nom_prenom'         => $etudiant->nom_prenom,
                'cin'                => $etudiant->cin,
                'numero_inscription' => $etudiant->numero_inscription,
            ];
        }

        uasort(
            $groups,
            fn ($a, $b) => [$a['niveau_numero'], $a['groupe_nom']] <=> [$b['niveau_numero'], $b['groupe_nom']]
        );

        foreach ($groups as &$group) {
            usort(
                $group['etudiants'],
                fn ($a, $b) => strnatcasecmp((string) $a['numero_inscription'], (string) $b['numero_inscription'])
            );
            unset($group['niveau_numero'], $group['groupe_nom']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * Filière / niveau / school year the export was built from, used for the
     * sheet's scope line and its file name.
     */
    private function docxContext(Request $request, $etudiants): array
    {
        $filiere = $request->filled('filiere_id')
            ? (string) (Filiere::whereKey($request->filiere_id)->value('nom') ?? '')
            : '';

        $niveau = $request->filled('niveau_id')
            ? $this->niveauLabel((int) (Niveau::whereKey($request->niveau_id)->value('numero') ?? 0))
            : '';

        return [
            'filiere'        => $filiere,
            'niveau'         => $niveau,
            'annee_scolaire' => (string) ($etudiants->first()?->anneeAcademique?->label ?? ''),
        ];
    }

    /**
     * "Nème année", first year spelled out, as on the other sheets.
     */
    private function niveauLabel(?int $numero): string
    {
        if (!$numero) {
            return '';
        }

        return $numero === 1 ? '1ère année' : $numero . 'ème année';
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom_prenom'        => 'required|string',
                'cin'               => 'required|string',
                'date_naissance'    => 'nullable|date',
                'lieu_naissance'    => 'nullable|string',
                'nationalite'       => 'nullable|string',
                'date_inscription'  => 'nullable|date',
                'groupe_id'         => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
            ]);

            return DB::transaction(function () use ($request) {
                $filiereCode = \App\Models\Groupe::with('niveau.filiere')
                    ->findOrFail($request->groupe_id)
                    ->niveau->filiere->code;

                $numero = $this->numeroService->generate(
                    $filiereCode,
                    $request->annee_academique_id
                );

$password = User::generateStrongPassword();

                // Matched on the CIN, which is the stable student identifier: the username
                // is generated once and never regenerated when the name or CIN changes.
                $username = app(UsernameGenerator::class)->reserveFor($request->nom_prenom, 'etudiant');

                $user = User::firstOrCreate(
                    ['username' => $username],
                    [
                        'name'               => $request->nom_prenom,
                        'password'           => Hash::make($password),
                        'password_encrypted' => Crypt::encryptString($password),
                        'password_original_encrypted' => Crypt::encryptString($password),
                        'role'               => 'etudiant',
                    ]
                );

                $etudiant = Etudiant::updateOrCreate(
                    ['cin' => strtoupper($request->cin)],
                    [
                        'user_id'             => $user->id,
                        'groupe_id'           => $request->groupe_id,
                        'annee_academique_id' => $request->annee_academique_id,
                        'nom_prenom'          => $request->nom_prenom,
                        'nom_ar'              => $request->nom_ar,
                        'cin'                 => strtoupper($request->cin),
                        'date_naissance'      => $request->date_naissance,
                        'lieu_naissance'      => $request->lieu_naissance,
                        'nationalite'         => $request->nationalite ?: 'Marocaine',
                        'date_inscription'    => $request->date_inscription,
                        'numero_inscription'  => $numero,
                        'status'              => 'active',
                        'date_naissance_ar'   => $request->date_naissance_ar,
                        'lieu_naissance_ar'   => $request->lieu_naissance_ar,
                        'cin_ar'              => $request->cin_ar,
                        'nationalite_ar'      => $request->nationalite_ar,
                        'numero_inscription_ar'=> $request->numero_inscription_ar,
                        'date_inscription_ar' => $request->date_inscription_ar,
                    ]
                );

                return response()->json(['success' => true, 'data' => $etudiant->load('groupe.niveau.filiere'), 'message' => 'Étudiant créé']);
            });
        } catch (\Exception $e) {
            \Log::error('EtudiantController::store error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de l\'étudiant'], 500);
        }
    }

    public function translateLieu(Request $request): JsonResponse
    {
        try {
            $request->validate(['lieu' => 'required|string|max:100']);

            $lieuAr = app(GeminiService::class)->translateLieu($request->lieu);

            return response()->json([
                'success' => true,
                'data'    => ['lieu_ar' => $lieuAr],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('EtudiantController::translateLieu error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la traduction du lieu'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $request->validate([
                'nom_prenom'         => 'required|string',
                'cin'                => 'required|string',
                'date_naissance'     => 'nullable|date',
                'groupe_id'          => 'required|exists:groupes,id',
                'lieu_naissance'     => 'nullable|string',
                'nationalite'        => 'nullable|string',
                'date_inscription'   => 'nullable|date',
                'nom_ar'             => 'nullable|string',
                'date_naissance_ar'  => 'nullable|string',
                'lieu_naissance_ar'  => 'nullable|string',
                'cin_ar'             => 'nullable|string',
                'nationalite_ar'     => 'nullable|string',
                'numero_inscription_ar' => 'nullable|string',
                'date_inscription_ar' => 'nullable|string',
                'numero_inscription' => ['nullable', 'string', 'max:50',
                    Rule::unique('etudiants', 'numero_inscription')->ignore((int) ($request->route('id') ?? 0))],
            ]);

            $etudiant = Etudiant::with('user')->findOrFail($id);

            // The registration number is only ever hand-editable in the final
            // year (année diplômante) when the diploma is about to be printed.
            $numeroInscription = $request->input('numero_inscription');
            $isFinalYear = $etudiant->groupe?->niveau?->numero === $etudiant->groupe?->niveau?->filiere?->nombre_annees;
            if ($numeroInscription !== null && trim((string) $numeroInscription) !== '' && !$isFinalYear) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le N° inscription est modifiable uniquement en année diplômante (dernière année de la filière).',
                ], 422);
            }

            $etudiant->update([
                'nom_prenom'         => $request->nom_prenom,
                'cin'                => strtoupper($request->cin),
                'date_naissance'     => $request->date_naissance,
                'lieu_naissance'     => $request->lieu_naissance,
                'nationalite'        => $request->nationalite,
                'date_inscription'   => $request->date_inscription,
                'groupe_id'          => $request->groupe_id,
                'nom_ar'             => $request->nom_ar,
                'date_naissance_ar'  => $request->date_naissance_ar,
                'lieu_naissance_ar'  => $request->lieu_naissance_ar,
                'cin_ar'             => $request->cin_ar,
                'nationalite_ar'     => $request->nationalite_ar,
                'numero_inscription_ar' => $request->numero_inscription_ar,
                'date_inscription_ar' => $request->date_inscription_ar,
            ]);

            if ($isFinalYear && $numeroInscription !== null && trim((string) $numeroInscription) !== '') {
                $numero = trim((string) $numeroInscription);
                $etudiant->update([
                    'numero_inscription'    => $numero,
                    'numero_inscription_ar' => $numero,
                ]);
            }

            if ($etudiant->user) {
                // Only the display name follows the record; the username stays stable so
                // that renaming a student never breaks an existing login.
                $etudiant->user->update(['name' => $request->nom_prenom]);
            }

            return response()->json(['success' => true, 'data' => $etudiant->load('groupe.niveau.filiere'), 'message' => 'Étudiant modifié']);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la modification de l\'étudiant'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $etudiant = Etudiant::with('user.formateur')->findOrFail($id);
            $user = $etudiant->user;
            $etudiant->delete();
            if ($user && !$user->formateur) {
                $user->delete();
            }
            return response()->json(['success' => true, 'message' => 'Stagiaire supprimé']);
        } catch (\Exception $e) {
            \Log::error('EtudiantController::destroy error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression'], 500);
        }
    }
}
