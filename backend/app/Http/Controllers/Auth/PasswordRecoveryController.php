<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthCode;
use App\Models\User;
use App\Services\AuthCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Password recovery for every role: username -> code sent to the verified contact email.
 *
 * Both endpoints answer identically whatever the reason for the failure, so that the
 * API never discloses whether a username exists or has a usable email address.
 */
class PasswordRecoveryController extends Controller
{
    /** Deliberately identical for every failure mode. */
    private const GENERIC_FAILURE = 'Identifiant ou code invalide. Veuillez recommencer.';

    public function __construct(
        private readonly AuthCodeService $authCodes
    ) {}

    public function sendCode(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:60'],
        ]);

        $user = User::where('username', $this->normalize($request->username))->first();

        if ($user && $user->hasVerifiedContactEmail()) {
            $this->authCodes->issue($user, AuthCode::PURPOSE_PASSWORD_RESET, $request->ip());
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte correspond à cet identifiant et possède une adresse email vérifiée, un code vient d\'être envoyé.',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'username'         => ['required', 'string', 'max:60'],
            'code'             => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:10', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\-_.+])/u'],
            'confirm_password' => ['required', 'same:new_password'],
        ], [
            'new_password.regex' => 'Le mot de passe doit contenir au moins 10 caractères avec des majuscules, minuscules, chiffres et symboles',
        ]);

        $user = User::where('username', $this->normalize($request->username))->first();

        $isValid = $user
            && $user->hasVerifiedContactEmail()
            && $this->authCodes->verify($user, AuthCode::PURPOSE_PASSWORD_RESET, $request->code);

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'message' => self::GENERIC_FAILURE,
            ], 401);
        }

        $user->update([
            'password'            => Hash::make($request->new_password),
            'password_encrypted'  => Crypt::encryptString($request->new_password),
            'password_changed_at' => now(),
        ]);

        $user->authCodes()->update(['consumed_at' => now()]);

        // The password changed outside of a session: drop every active token.
        $user->tokens()->delete();

        Log::info('Réinitialisation de mot de passe par code', ['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès. Veuillez vous reconnecter.',
        ]);
    }

    private function normalize(string $username): string
    {
        return mb_strtolower(trim($username));
    }
}