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
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email ou mot de passe incorrect',
                ], 401);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'success' => true,
                'data'    => [
                    'token' => $token,
                    'role'  => $user->role,
                    'name'  => $user->name,
                    'email' => $user->email,
                ],
            ]);
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
            return response()->json(['success' => true, 'data' => $user]);
        } catch (\Exception $e) {
            Log::error('Erreur profil utilisateur: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement du profil'], 500);
        }
    }
}