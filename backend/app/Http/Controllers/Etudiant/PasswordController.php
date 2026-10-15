<?php

namespace App\Http\Controllers\Etudiant;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * First-login password status only.
 *
 * The change-password flow now lives in App\Http\Controllers\Auth\AccountController
 * (shared by every role) and the forgot-password flow was replaced by the
 * username + emailed-code recovery in App\Http\Controllers\Auth\PasswordRecoveryController.
 */
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
}
