<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\NumeroInscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            $query = Etudiant::with(['user', 'groupe.niveau.filiere', 'anneeAcademique']);

            if ($request->filled('annee_academique_id')) {
                $query->where('annee_academique_id', $request->annee_academique_id);
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

            $etudiants = $query->orderBy('nom_prenom')->get();
            $etudiants->each(fn($e) => $e->user?->append('password_plain'));
            return response()->json(['success' => true, 'data' => $etudiants]);
        } catch (\Exception $e) {
            \Log::error('EtudiantController::index error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'nom_prenom'        => 'required|string',
                'cin'               => 'required|string',
                'date_naissance'    => 'nullable|date',
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

                $email = strtolower($request->cin) . '@ifp.ma';
                $password = Str::random(12);

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'               => $request->nom_prenom,
                        'password'           => Hash::make($password),
                        'password_encrypted' => Crypt::encryptString($password),
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
                        'cin'                 => strtoupper($request->cin),
                        'date_naissance'      => $request->date_naissance,
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
            ]);

            $etudiant = Etudiant::with('user')->findOrFail($id);

            $newEmail = strtolower($request->cin) . '@ifp.ma';

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

            if ($etudiant->user) {
                $updates = ['name' => $request->nom_prenom];
                if ($etudiant->user->email !== $newEmail) {
                    $updates['email'] = $newEmail;
                }
                $etudiant->user->update($updates);
            }

            return response()->json(['success' => true, 'data' => $etudiant->load('groupe.niveau.filiere'), 'message' => 'Étudiant modifié']);
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
