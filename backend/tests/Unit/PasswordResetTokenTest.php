<?php

namespace Tests\Unit;

use App\Models\EtudiantPasswordReset;
use Tests\TestCase;

class PasswordResetTokenTest extends TestCase
{
    public function test_token_hash_is_deterministic_sha256(): void
    {
        $hash = EtudiantPasswordReset::hashToken('plain-token-value');

        $this->assertSame(hash('sha256', 'plain-token-value'), $hash);
        $this->assertSame(64, strlen($hash));
        $this->assertNotSame('plain-token-value', $hash);
    }

    public function test_different_tokens_produce_different_hashes(): void
    {
        $this->assertNotSame(
            EtudiantPasswordReset::hashToken('token-a'),
            EtudiantPasswordReset::hashToken('token-b')
        );
    }

    public function test_fresh_token_is_usable(): void
    {
        $reset = new EtudiantPasswordReset([
            'token_hash' => EtudiantPasswordReset::hashToken('abc'),
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->assertTrue($reset->isUsable());
    }

    public function test_used_token_is_not_usable(): void
    {
        $reset = new EtudiantPasswordReset([
            'token_hash' => EtudiantPasswordReset::hashToken('abc'),
            'expires_at' => now()->addMinutes(5),
            'used_at'   => now(),
        ]);

        $this->assertFalse($reset->isUsable());
    }

    public function test_expired_token_is_not_usable(): void
    {
        $reset = new EtudiantPasswordReset([
            'token_hash' => EtudiantPasswordReset::hashToken('abc'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse($reset->isUsable());
    }
}
