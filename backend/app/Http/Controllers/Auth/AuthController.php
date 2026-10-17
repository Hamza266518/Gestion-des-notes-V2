<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Wrong passwords allowed for one identifier + IP before a short lockout. */
    private const MAX_LOGIN_ATTEMPTS = 5;

    /** Lockout duration, in seconds, once the attempts above are used up. */
    private const LOGIN_LOCKOUT_SECONDS = 300;

    public function login(Request $request)
    {
        try {
            $request->validate([
                'username' => 'required|string|max:60',
                'password' => 'required',
            ]);

            $username = mb_strtolower(trim($request->username));

            // Throttled per identifier + IP: a single address cannot grind through
            // passwords, and an attacker cannot lock an account out for everyone else.
            $throttleKey = 'login:' . sha1($username . '|' . $request->ip());

            if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_LOGIN_ATTEMPTS)) {
                $seconds = RateLimiter::availableIn($throttleKey);

                Log::warning('Connexion bloquée par limitation', [
                    'username' => $username,
                    'ip'       => $request->ip(),
                    'seconds'  => $seconds,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Trop de tentatives de connexion. Réessayez dans {$seconds} seconde(s).",
                ], 429);
            }

            $user = User::where('username', $username)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                RateLimiter::hit($throttleKey, self::LOGIN_LOCKOUT_SECONDS);

                // The password is never logged: identifier and IP are enough to spot abuse.
                Log::warning('Échec de connexion', [
                    'username' => $username,
                    'ip'       => $request->ip(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Identifiant ou mot de passe incorrect',
                ], 401);
            }

            RateLimiter::clear($throttleKey);

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
        } catch (ValidationException $e) {
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