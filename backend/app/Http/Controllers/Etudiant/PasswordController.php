<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\EtudiantPasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    /**
     * Check if user has changed password from default
     */
    public function checkFirstLogin()
    {
        try {
            $user = auth()->user();
            
            return response()->json([
                'success' => true,
                'needs_password_change' => $user->password_changed_at === null,
                'message' => $user->password_changed_at === null ? 'First login - please change password' : 'Password already changed'
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error checking password status'], 500);
        }
    }

    /**
     * Change password on first login or anytime
     * On first login, the old_password should be the initial password (CIN + symbol)
     */
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'old_password' => 'required|string',
                'new_password' => 'required|string|min:10|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\-_.+])/u',
                'confirm_password' => 'required|same:new_password',
            ], [
                'new_password.regex' => 'Le mot de passe doit contenir au moins 10 caractères avec des majuscules, minuscules, chiffres et symboles',
            ]);

            $user = auth()->user();

            // Check if this is first password change or regular password change
            $isFirstChange = $user->password_changed_at === null;

            if ($isFirstChange) {
                // For first change, verify against original password
                try {
                    $decryptedOriginal = Crypt::decryptString($user->password_original_encrypted ?? $user->password_encrypted);
                    if ($decryptedOriginal !== $request->old_password) {
                        return response()->json(['success' => false, 'message' => 'L\'ancien mot de passe est incorrect'], 422);
                    }
                } catch (\Exception $e) {
                    \Log::error('Password change: decryption error (first change): ' . $e->getMessage());
                    return response()->json(['success' => false, 'message' => 'Erreur de vérification de mot de passe'], 500);
                }
            } else {
                // For subsequent changes, verify against current password
                try {
                    $decryptedCurrent = Crypt::decryptString($user->password_encrypted);
                    if ($decryptedCurrent !== $request->old_password) {
                        return response()->json(['success' => false, 'message' => 'L\'ancien mot de passe est incorrect'], 422);
                    }
                } catch (\Exception $e) {
                    \Log::error('Password change: decryption error (subsequent change): ' . $e->getMessage());
                    return response()->json(['success' => false, 'message' => 'Erreur de vérification de mot de passe'], 500);
                }
            }

            // Update password
            $encryptedNew = Crypt::encryptString($request->new_password);
            $user->update([
                'password' => Hash::make($request->new_password),
                'password_encrypted' => $encryptedNew,
                'password_changed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe modifié avec succès'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Password change error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du changement de mot de passe'], 500);
        }
    }

    /**
     * Verify identity for forgot password (Step 1: verify old password + CIN)
     */
    public function verifyForgotPassword(Request $request)
    {
        try {
            $request->validate([
                'old_password' => 'required|string',
                'cin' => 'required|string',
            ]);

            // Find etudiant by CIN
            $etudiant = Etudiant::where('cin', strtoupper($request->cin))->first();

            if (!$etudiant) {
                return response()->json(['success' => false, 'message' => 'CIN non trouvé'], 404);
            }

            // Get user
            $user = User::find($etudiant->user_id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Compte utilisateur non trouvé'], 404);
            }

            // Try to decrypt and verify the original password (stored at account creation)
            try {
                // Try decrypting the original password
                $decryptedOriginal = Crypt::decryptString($user->password_original_encrypted ?? $user->password_encrypted);
                
                if ($decryptedOriginal !== $request->old_password) {
                    return response()->json(['success' => false, 'message' => 'L\'ancien mot de passe est incorrect'], 422);
                }
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Erreur de vérification'], 500);
            }

            // Identity verified - issue a single-use, expiring, server-side token
            $plainToken = Str::random(64);

            EtudiantPasswordReset::where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            EtudiantPasswordReset::create([
                'user_id'    => $user->id,
                'token_hash' => EtudiantPasswordReset::hashToken($plainToken),
                'expires_at' => now()->addMinutes(5),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Identité vérifiée',
                'temp_token' => $plainToken,
                'student_name' => $etudiant->nom_prenom,
            ]);
        } catch (\Exception $e) {
            \Log::error('Forgot password verification error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la vérification'], 500);
        }
    }

    /**
     * Reset password using forgot password (Step 2: set new password)
     */
    public function resetForgotPassword(Request $request)
    {
        try {
            $request->validate([
                'temp_token' => 'required|string',
                'new_password' => 'required|string|min:10|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\-_.+])/u',
                'confirm_password' => 'required|same:new_password',
            ], [
                'new_password.regex' => 'Le mot de passe doit contenir au moins 10 caractères avec des majuscules, minuscules, chiffres et symboles',
            ]);

            // Consume the single-use token (stored hashed, expiring, already-used tokens are rejected)
            $reset = EtudiantPasswordReset::where('token_hash', EtudiantPasswordReset::hashToken($request->temp_token))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first();

            if (!$reset) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token invalide ou expiré. Veuillez recommencer.',
                ], 401);
            }

            $user = User::find($reset->user_id);
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Compte introuvable'], 401);
            }

            $reset->update(['used_at' => now()]);

            $encryptedNew = Crypt::encryptString($request->new_password);

            $user->update([
                'password' => Hash::make($request->new_password),
                'password_encrypted' => $encryptedNew,
                'password_changed_at' => now(),
            ]);

            // Password changed outside a session: revoke every active token
            $user->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe réinitialisé avec succès. Veuillez vous reconnecter.'
            ]);
        } catch (\Exception $e) {
            \Log::error('Reset forgot password error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de la réinitialisation'], 500);
        }
    }
}
