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

    public const STATUS_SENT         = 'sent';
    public const STATUS_COOLDOWN     = 'cooldown';
    public const STATUS_NO_RECIPIENT = 'no_recipient';
    public const STATUS_FAILED       = 'failed';

    private const CODE_LENGTH = 6;

    /**
     * Issue and mail a code.
     *
     * Returns a STATUS_* constant. A cooldown and a failed send both used to
     * come back as null, so the account screen told the user to "wait" even
     * when nothing had been sent. Callers can now tell the two apart.
     */
    public function issue(User $user, string $purpose, ?string $ip = null): string
    {
        if (!in_array($purpose, [AuthCode::PURPOSE_EMAIL_VERIFICATION, AuthCode::PURPOSE_PASSWORD_RESET], true)) {
            throw new \InvalidArgumentException("Purpose de code inconnue: {$purpose}");
        }

        $recipient = trim((string) $user->contact_email);

        if ($recipient === '') {
            return self::STATUS_NO_RECIPIENT;
        }

        $isCoolingDown = AuthCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))
            ->exists();

        if ($isCoolingDown) {
            return self::STATUS_COOLDOWN;
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

            return self::STATUS_FAILED;
        }

        return self::STATUS_SENT;
    }

    /**
     * Consume a code. Single-use, expiring, and attempt-limited.
     */
    public function verify(User $user, string $purpose, string $plainCode): bool
    {
        $record = $this->usableRecord($user, $purpose);

        if (!$record || !$this->codeMatches($record, $plainCode)) {
            return false;
        }

        $record->update(['consumed_at' => now()]);

        return true;
    }

    /**
     * Validate a code without consuming it, so a multi-step flow can confirm the
     * email before asking for the new password (the reset still consumes it).
     * A wrong code counts an attempt exactly like verify(), so guessing is
     * throttled the same way in both paths.
     */
    public function check(User $user, string $purpose, string $plainCode): bool
    {
        $record = $this->usableRecord($user, $purpose);

        return $record !== null && $this->codeMatches($record, $plainCode);
    }

    /** The latest unconsumed, unexpired, non-exhausted code, or null. */
    private function usableRecord(User $user, string $purpose): ?AuthCode
    {
        $record = AuthCode::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$record) {
            return null;
        }

        if ($record->expires_at->isPast() || $record->attempts >= $record->max_attempts) {
            $record->update(['consumed_at' => now()]);

            return null;
        }

        return $record;
    }

    private function codeMatches(AuthCode $record, string $plainCode): bool
    {
        $plainCode = trim($plainCode);

        if ($plainCode === '') {
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

        return true;
    }

    private function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::CODE_LENGTH) - 1), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }
}