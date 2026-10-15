<?php

namespace App\Services;

use App\Mail\VerificationCodeMail;
use App\Models\AuthCode;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuthCodeService
{
    public const TTL_MINUTES = 10;
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 60;

    private const CODE_LENGTH = 6;

    /**
     * Issue a fresh code, invalidating any live code for the same purpose.
     *
     * Returns null when the resend cooldown is still running, or when the email
     * could not be delivered (in which case nothing is left usable behind).
     */
    public function issue(User $user, string $purpose, ?string $ip = null): ?string
    {
        if (!in_array($purpose, [AuthCode::PURPOSE_EMAIL_VERIFICATION, AuthCode::PURPOSE_PASSWORD_RESET], true)) {
            throw new \InvalidArgumentException("Purpose de code inconnue: {$purpose}");
        }

        $recipient = trim((string) $user->contact_email);

        if ($recipient === '') {
            return null;
        }

        $isCoolingDown = AuthCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->exists();

        if ($isCoolingDown) {
            return null;
        }

        AuthCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = $this->generateCode();

        $record = AuthCode::create([
            'user_id'     => $user->id,
            'purpose'     => $purpose,
            'code_hash'   => AuthCode::hashCode($code),
            'attempts'    => 0,
            'max_attempts'=> self::MAX_ATTEMPTS,
            'expires_at'  => now()->addMinutes(self::TTL_MINUTES),
            'ip_address'  => $ip,
        ]);

        try {
            Mail::to($recipient)->send(new VerificationCodeMail($code, $purpose, self::TTL_MINUTES));
        } catch (\Throwable $e) {
            $record->delete();

            Log::error('AuthCodeService: envoi du code impossible', [
                'user_id' => $user->id,
                'purpose' => $purpose,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        return $code;
    }

    /**
     * Consume a code. Single-use, expiring, and attempt-limited.
     */
    public function verify(User $user, string $purpose, string $plainCode): bool
    {
        $plainCode = trim($plainCode);

        if ($plainCode === '') {
            return false;
        }

        $record = AuthCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            return false;
        }

        if ($record->expires_at->isPast() || $record->attempts >= $record->max_attempts) {
            $record->update(['consumed_at' => now()]);

            return false;
        }

        if (!hash_equals($record->code_hash, AuthCode::hashCode($plainCode))) {
            $record->increment('attempts');
            $record->refresh();

            if ($record->attempts >= $record->max_attempts) {
                $record->update(['consumed_at' => now()]);
            }

            return false;
        }

        $record->update(['consumed_at' => now()]);

        return true;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}