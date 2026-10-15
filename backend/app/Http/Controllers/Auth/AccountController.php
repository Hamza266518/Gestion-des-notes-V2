<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthCode;
use App\Models\User;
use App\Services\AuthCodeService;
use App\Services\UsernameGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(
        private readonly AuthCodeService $authCodes
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data'    => $this->accountPayload($user),
        ]);
    }

    /**
     * Save a new contact email and send its verification code.
     *
     * Replacing an already verified email requires the current password, so that a
     * hijacked session cannot silently repoint the account recovery channel.
     */
    public function updateEmail(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'email'            => ['required', 'email', 'max:190', Rule::unique('users', 'contact_email')->ignore($user->id)],
            'current_password' => ['nullable', 'string'],
        ]);

        if ($user->hasVerifiedContactEmail()) {
            $request->validate([
                'current_password' => ['required', 'string'],
            ]);

            if (!Hash::check((string) $request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe actuel incorrect',
                ], 422);
            }
        }

        $user->forceFill([
            'contact_email'           => mb_strtolower(trim($request->email)),
            'contact_email_verified_at' => null,
        ])->save();

        $sent = $this->authCodes->issue($user, AuthCode::PURPOSE_EMAIL_VERIFICATION, $request->ip()) !== null;

        return response()->json([
            'success' => true,
            'data'    => $this->accountPayload($user->refresh()),
            'message' => $sent
                ? 'Adresse email enregistrée. Un code de vérification vient d\'être envoyé.'
                : 'Adresse email enregistrée. Un code a déjà été envoyé récemment, veuillez patienter avant de réessayer.',
        ]);
    }

    public function verifyEmail(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'code' => ['required', 'string'],
        ]);

        if (!filled($user->contact_email)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune adresse email à vérifier',
            ], 422);
        }

        if (!$this->authCodes->verify($user, AuthCode::PURPOSE_EMAIL_VERIFICATION, $request->code)) {
            return response()->json([
                'success' => false,
                'message' => 'Code invalide ou expiré. Veuillez en demander un nouveau.',
            ], 422);
        }

        $user->forceFill(['contact_email_verified_at' => now()])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->accountPayload($user->refresh()),
            'message' => 'Adresse email vérifiée',
        ]);
    }

    public function resendEmailVerification(Request $request)
    {
        $user = $request->user();

        if (!filled($user->contact_email)) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune adresse email à vérifier',
            ], 422);
        }

        $sent = $this->authCodes->issue($user, AuthCode::PURPOSE_EMAIL_VERIFICATION, $request->ip()) !== null;

        return response()->json([
            'success' => true,
            'message' => $sent
                ? 'Un nouveau code vient d\'être envoyé'
                : 'Un code a déjà été envoyé récemment, veuillez patienter avant de réessayer.',
        ]);
    }

    public function removeEmail(Request $request)
    {
        $user = $request->user();

        $user->authCodes()->update(['consumed_at' => now()]);

        $user->forceFill([
            'contact_email'           => null,
            'contact_email_verified_at' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->accountPayload($user->refresh()),
            'message' => 'Adresse email supprimée',
        ]);
    }

    public function changeUsername(Request $request)
    {
        $user = $request->user();
        $generator = app(UsernameGenerator::class);

        $request->validate([
            'username' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9]+(?:\.[a-z0-9]+)*$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
        ]);

        $username = mb_strtolower(trim($request->username));

        if (!$generator->isValidFormat($username)) {
            return response()->json([
                'success' => false,
                'message' => 'L\'identifiant ne peut contenir que des lettres, chiffres et points',
            ], 422);
        }

        $user->forceFill(['username' => $username])->save();

        return response()->json([
            'success' => true,
            'data'    => $this->accountPayload($user->refresh()),
            'message' => 'Identifiant mis à jour',
        ]);
    }

    /**
     * Shared by every role (the previous etudiant-only endpoint kept the same path).
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:10|regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\-_.+])/u',
            'confirm_password' => 'required|same:new_password',
        ], [
            'new_password.regex' => 'Le mot de passe doit contenir au moins 10 caractères avec des majuscules, minuscules, chiffres et symboles',
        ]);

        // On first login the check is against the originally issued password.
        $expected = $user->password_changed_at === null
            ? ($user->password_original_encrypted ?? $user->password_encrypted)
            : $user->password_encrypted;

        try {
            $matches = Crypt::decryptString((string) $expected) === $request->old_password;
        } catch (\Exception $e) {
            Log::error('Changement de mot de passe: déchiffrement impossible', ['user_id' => $user->id]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur de vérification du mot de passe',
            ], 500);
        }

        if (!$matches) {
            return response()->json([
                'success' => false,
                'message' => 'L\'ancien mot de passe est incorrect',
            ], 422);
        }

        $user->update([
            'password'            => Hash::make($request->new_password),
            'password_encrypted'  => Crypt::encryptString($request->new_password),
            'password_changed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès',
        ]);
    }

    private function accountPayload(User $user): array
    {
        return [
            'id'                       => $user->id,
            'name'                     => $user->name,
            'username'                 => $user->username,
            'role'                     => $user->role,
            'contact_email'            => $user->contact_email,
            'contact_email_verified_at'=> $user->contact_email_verified_at?->toIso8601String(),
            'password_changed_at'      => $user->password_changed_at?->toIso8601String(),
        ];
    }
}