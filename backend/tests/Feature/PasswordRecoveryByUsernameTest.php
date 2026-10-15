<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\AuthCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordRecoveryByUsernameTest extends TestCase
{
    private const NEW_PASSWORD = 'Recup2ration!2026';

    /** @var int[] */
    private array $userIds = [];

    protected function tearDown(): void
    {
        foreach ($this->userIds as $userId) {
            User::find($userId)?->tokens()->delete();
            AuthCode::where('user_id', $userId)->delete();
            User::where('id', $userId)->delete();
        }

        $this->userIds = [];

        parent::tearDown();
    }

    private function makeUser(array $attributes = [], string $username = 'recover.tester'): User
    {
        $user = User::create(array_merge([
            'name'     => 'Recover Tester',
            'username' => $username,
            'password' => Hash::make('secret1234'),
            'role'     => 'etudiant',
        ], $attributes));

        $this->userIds[] = $user->id;

        return $user;
    }

    private function verifiedUser(string $username = 'recover.tester'): User
    {
        return $this->makeUser([
            'contact_email'            => 'recover.tester@example.com',
            'contact_email_verified_at' => now(),
        ], $username);
    }

    private function requestCode(User $user): string
    {
        Mail::fake();

        $this->postJson('/api/auth/password/forgot', ['username' => $user->username])->assertOk();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        $this->assertIsString($code);

        return $code;
    }

    public function test_a_code_is_mailed_to_the_verified_address(): void
    {
        $user = $this->verifiedUser();

        $code = $this->requestCode($user);

        $record = AuthCode::where('user_id', $user->id)
            ->where('purpose', AuthCode::PURPOSE_PASSWORD_RESET)
            ->latest('id')->first();

        $this->assertNotNull($record);
        $this->assertSame(hash('sha256', $code), $record->code_hash);
        $this->assertTrue($record->expires_at->isFuture());
    }

    public function test_an_unknown_username_gets_the_same_answered_response(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/password/forgot', ['username' => 'does.not.exist'])->assertOk();

        $this->assertSame(0, AuthCode::count());
        Mail::assertNothingSent();

        $verified = $this->verifiedUser();
        $known = $this->postJson('/api/auth/password/forgot', ['username' => $verified->username])->assertOk();

        $this->assertSame(
            $response->json('message'),
            $known->json('message'),
            'La réponse ne doit pas révéler si l\'identifiant existe'
        );
    }

    public function test_no_code_is_sent_when_the_email_is_missing_or_unverified(): void
    {
        Mail::fake();

        $noEmail = $this->makeUser([], 'recover.noemail');
        $unverified = $this->makeUser(['contact_email' => 'pending@example.com'], 'recover.unverified');

        $this->postJson('/api/auth/password/forgot', ['username' => $noEmail->username])->assertOk();
        $this->postJson('/api/auth/password/forgot', ['username' => $unverified->username])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_the_username_is_matched_regardless_of_case(): void
    {
        $user = $this->verifiedUser();
        Mail::fake();

        $this->postJson('/api/auth/password/forgot', ['username' => strtoupper($user->username)])->assertOk();

        Mail::assertSent(VerificationCodeMail::class, 1);
    }

    public function test_the_new_password_is_applied_and_all_sessions_revoked(): void
    {
        $user = $this->verifiedUser();
        $user->createToken('stale-session')->plainTextToken;

        $code = $this->requestCode($user);

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => $code,
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertOk()->assertJsonPath('success', true);

        $user->refresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertSame(0, $user->tokens()->count(), 'Toutes les sessions doivent être révoquées');

        // The old password no longer authenticates.
        $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => 'secret1234'])
            ->assertStatus(401);

        $this->postJson('/api/auth/login', ['username' => $user->username, 'password' => self::NEW_PASSWORD])
            ->assertOk();
    }

    public function test_the_reset_code_is_single_use(): void
    {
        $user = $this->verifiedUser();
        $code = $this->requestCode($user);

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => $code,
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => $code,
            'new_password'     => 'AutreMotDePasse9!',
            'confirm_password' => 'AutreMotDePasse9!',
        ])->assertStatus(401);

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_every_reset_failure_returns_the_same_message(): void
    {
        $user = $this->verifiedUser();
        $code = $this->requestCode($user);

        $unknownUser = $this->postJson('/api/auth/password/reset', [
            'username'         => 'does.not.exist',
            'code'             => $code,
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertStatus(401);

        $wrongCode = $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => '000000',
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertStatus(401);

        $this->assertSame($unknownUser->json('message'), $wrongCode->json('message'));

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => $code,
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertOk();

        // The password was untouched by the failed attempts.
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
    }

    public function test_recovery_is_refused_for_an_account_without_a_verified_email(): void
    {
        $user = $this->makeUser(['contact_email' => 'pending@example.com'], 'recover.pending');

        $this->postJson('/api/auth/password/forgot', ['username' => $user->username])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => '123456',
            'new_password'     => self::NEW_PASSWORD,
            'confirm_password' => self::NEW_PASSWORD,
        ])->assertStatus(401);

        $this->assertTrue(Hash::check('secret1234', $user->refresh()->password));
    }

    public function test_a_weak_new_password_is_rejected(): void
    {
        $user = $this->verifiedUser();
        $code = $this->requestCode($user);

        $this->postJson('/api/auth/password/reset', [
            'username'         => $user->username,
            'code'             => $code,
            'new_password'     => 'weak',
            'confirm_password' => 'weak',
        ])->assertStatus(422)->assertJsonValidationErrors('new_password');
    }
}