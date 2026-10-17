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
 * Password recovery for every role: username (or contact email) -> code sent to the
 * verified contact email, then verified, then the new password.
 *
 * Every endpoint answers identically whatever the reason for the failure, so that
 * the API never discloses whether an account exists or has a usable email address.
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
            'username' => ['required', 'string', 'max:190'],
        ]);

        $user = $this->findUser($request->username);

        if ($user && $user->hasVerifiedContactEmail()) {
            $this->authCodes->issue($user, AuthCode::PURPOSE_PASSWORD_RESET, $request->ip());
        }

        return response()->json([
            'success' => true,
            'message' => 'Si un compte correspond à cet identifiant et possède une adresse email vérifiée, un code vient d\'être envoyé.',
        ]);
    }

    /**
     * Step 2: confirm the code before the new password is asked for. The code is
     * only checked here; reset() still consumes it, so this adds no way in.
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'code'     => ['required', 'string'],
        ]);

        $user = $this->findUser($request->username);

        $isValid = $user
            && $user->hasVerifiedContactEmail()
            && $this->authCodes->check($user, AuthCode::PURPOSE_PASSWORD_RESET, $request->code);

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'message' => self::GENERIC_FAILURE,
            ], 401);
        }

        return response()->json([
            'success' => true,
            'message' => 'Code vérifié. Choisissez maintenant votre nouveau mot de passe.',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'username'         => ['required', 'string', 'max:190'],
            'code'             => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:10', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\-_.+])/u'],
            'confirm_password' => ['required', 'same:new_password'],
        ], [
            'new_password.regex' => 'Le mot de passe doit contenir au moins 10 caractères avec des majuscules, minuscules, chiffres et symboles',
        ]);

        $user = $this->findUser($request->username);

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

    /**
     * Resolve the account from what the user typed: a username, or the contact
     * email itself. Emails are stored lower-cased, and the comparison is
     * case-insensitive on the column so an old mixed-case row still resolves.
     */
    private function findUser(string $identifier): ?User
    {
        $identifier = mb_strtolower(trim($identifier));

        if ($identifier === '') {
            return null;
        }

        if (str_contains($identifier, '@')) {
            return User::whereRaw('LOWER(contact_email) = ?', [$identifier])->first();
        }

        return User::where('username', $identifier)->first();
    }
}