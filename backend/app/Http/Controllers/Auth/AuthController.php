<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string|max:60',
                'password' => 'required',
            ]);

            $user = User::where('username', mb_strtolower(trim($request->username)))->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant ou mot de passe incorrect',
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'data'    => [
                    'token'                    => $token,
                    'role'                     => $user->role,
                    'name'                     => $user->name,
                    'username'                 => $user->username,
                    'contact_email'            => $user->contact_email,
                    'contact_email_verified_at'=> $user->contact_email_verified_at?->toIso8601String(),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erreur connexion: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la connexion'], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->tokens()->delete();
            return response()->json(['success' => true, 'message' => 'Déconnecté']);
        } catch (\Exception $e) {
            Log::error('Erreur déconnexion: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la déconnexion'], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user()->load(['etudiant.groupe.niveau.filiere', 'formateur']);

            // Whitelist: never serialize password material on a general-purpose endpoint
            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                       => $user->id,
                    'name'                     => $user->name,
                    'username'                 => $user->username,
                    'contact_email'            => $user->contact_email,
                    'contact_email_verified_at'=> $user->contact_email_verified_at?->toIso8601String(),
                    'role'                     => $user->role,
                    'password_changed_at'      => $user->password_changed_at?->toIso8601String(),
                    'etudiant'                 => $user->etudiant,
                    'formateur'                => $user->formateur,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur profil utilisateur: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement du profil'], 500);
        }
    }
}