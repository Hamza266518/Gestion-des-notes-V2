<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

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

            // Identity verified - generate temporary token
            $tempToken = base64_encode(json_encode([
                'user_id' => $user->id,
                'etudiant_id' => $etudiant->id,
                'cin' => $etudiant->cin,
                'timestamp' => now()->timestamp,
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Identité vérifiée',
                'temp_token' => $tempToken,
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

            // Decode temp token
            try {
                $tokenData = json_decode(base64_decode($request->temp_token), true);
                if (!$tokenData || !isset($tokenData['user_id'], $tokenData['timestamp'])) {
                    return response()->json(['success' => false, 'message' => 'Token invalide'], 401);
                }

                // Check token expiry (5 minutes)
                if (now()->timestamp - $tokenData['timestamp'] > 300) {
                    return response()->json(['success' => false, 'message' => 'Token expiré. Veuillez recommencer.'], 401);
                }
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Token invalide'], 401);
            }

            // Get user and reset password
            $user = User::findOrFail($tokenData['user_id']);
            $encryptedNew = Crypt::encryptString($request->new_password);

            $user->update([
                'password' => Hash::make($request->new_password),
                'password_encrypted' => $encryptedNew,
                'password_original_encrypted' => $encryptedNew, // Update original for next forgot password
                'password_changed_at' => now(),
            ]);

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
