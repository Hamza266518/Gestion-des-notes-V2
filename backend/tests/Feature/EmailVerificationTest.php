<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\AuthCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    /** @var int[] */
    private array $userIds = [];

    protected function tearDown(): void
    {
        foreach ($this->userIds as $userId) {
            AuthCode::where('user_id', $userId)->delete();
            User::where('id', $userId)->delete();
        }

        $this->userIds = [];

        parent::tearDown();
    }

    private function makeUser(string $username = 'verify.tester'): User
    {
        $user = User::create([
            'name'     => 'Verify Tester',
            'username' => $username,
            'password' => Hash::make('secret1234'),
            'role'     => 'etudiant',
        ]);

        $this->userIds[] = $user->id;

        return $user;
    }

    /**
     * Saves the address and returns the code that was mailed.
     */
    private function saveEmail(User $user, string $email = 'verify.tester@example.com'): string
    {
        Mail::fake();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/account/email', ['email' => $email])
            ->assertOk();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;
            return true;
        });

        $this->assertIsString($code);

        return $code;
    }

    public function test_saving_an_email_stores_it_unverified_and_sends_a_code(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/account/email', ['email' => 'Verify.Tester@Example.COM'])
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'verify.tester@example.com')
            ->assertJsonPath('data.contact_email_verified_at', null);

        $user->refresh();

        $this->assertSame('verify.tester@example.com', $user->contact_email);
        $this->assertFalse($user->hasVerifiedContactEmail());

        $record = AuthCode::where('user_id', $user->id)
            ->where('purpose', AuthCode::PURPOSE_EMAIL_VERIFICATION)
            ->latest('id')->first();

        $this->assertNotNull($record);
        $this->assertSame(0, $record->attempts);
        $this->assertSame(5, $record->max_attempts);
        $this->assertTrue($record->expires_at->isFuture());
    }

    public function test_the_code_is_six_digits_and_stored_hashed(): void
    {
        $user = $this->makeUser();

        $code = $this->saveEmail($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $record = AuthCode::where('user_id', $user->id)
            ->where('purpose', AuthCode::PURPOSE_EMAIL_VERIFICATION)
            ->latest('id')->first();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $record->code_hash);
        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(hash('sha256', $code), $record->code_hash);
    }

    public function test_a_correct_code_marks_the_email_as_verified(): void
    {
        $user = $this->makeUser();
        $code = $this->saveEmail($user);

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_a_wrong_code_is_refused_and_counted(): void
    {
        $user = $this->makeUser();
        $code = $this->saveEmail($user);

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $this->otherCode($code)])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $record = AuthCode::where('user_id', $user->id)->latest('id')->first();

        $this->assertSame(1, $record->attempts);
        $this->assertNull($record->consumed_at);
        $this->assertFalse($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_a_code_cannot_be_replayed(): void
    {
        $user = $this->makeUser();
        $code = $this->saveEmail($user);

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $code])
            ->assertOk();

        // Clearing the flag makes a second verification possible, so the replay is meaningful.
        $user->refresh()->forceFill(['contact_email_verified_at' => null])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertFalse($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_attempts_are_capped_at_five_and_then_the_code_dies(): void
    {
        $user = $this->makeUser();
        $code = $this->saveEmail($user);
        $wrong = $this->otherCode($code);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user->refresh(), 'sanctum')
                ->postJson('/api/account/email/verify', ['code' => $wrong])
                ->assertStatus(422);
        }

        $record = AuthCode::where('user_id', $user->id)->latest('id')->first();

        $this->assertSame(5, $record->attempts);
        $this->assertNotNull($record->consumed_at);

        // The genuine code no longer works once the attempt budget is spent.
        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $code])
            ->assertStatus(422);

        $this->assertFalse($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $user = $this->makeUser();
        $code = $this->saveEmail($user);

        AuthCode::where('user_id', $user->id)->latest('id')->first()
            ->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/verify', ['code' => $code])
            ->assertStatus(422);

        $this->assertFalse($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_resending_within_the_cooldown_reuses_the_live_code(): void
    {
        $user = $this->makeUser();
        $this->saveEmail($user);

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/account/email/resend')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(
            1,
            AuthCode::where('user_id', $user->id)->where('purpose', AuthCode::PURPOSE_EMAIL_VERIFICATION)->count()
        );
    }

    public function test_replacing_a_verified_email_requires_the_current_password(): void
    {
        $user = $this->makeUser();
        $this->saveEmail($user);

        $user->refresh()->forceFill(['contact_email_verified_at' => now()])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->putJson('/api/account/email', ['email' => 'second@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($user->refresh(), 'sanctum')
            ->putJson('/api/account/email', ['email' => 'second@example.com', 'current_password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->actingAs($user->refresh(), 'sanctum')
            ->putJson('/api/account/email', ['email' => 'second@example.com', 'current_password' => 'secret1234'])
            ->assertOk()
            ->assertJsonPath('data.contact_email', 'second@example.com');

        $this->assertNull($user->refresh()->contact_email_verified_at);
    }

    public function test_an_email_already_used_by_another_account_is_refused(): void
    {
        $user = $this->makeUser();
        $this->saveEmail($user); // verify.tester@example.com is now taken by $user

        $other = $this->track(User::create([
            'name'     => 'Other Tester',
            'username' => 'other.tester',
            'password' => Hash::make('secret1234'),
            'role'     => 'formateur',
        ]));

        $this->actingAs($other, 'sanctum')
            ->putJson('/api/account/email', ['email' => 'VERIFY.TESTER@EXAMPLE.COM'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    private function track(User $user): User
    {
        $this->userIds[] = $user->id;

        return $user;
    }

    public function test_removing_the_email_clears_the_verification(): void
    {
        $user = $this->makeUser();
        $this->saveEmail($user);

        $user->refresh()->forceFill(['contact_email_verified_at' => now()])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->deleteJson('/api/account/email')
            ->assertOk()
            ->assertJsonPath('data.contact_email', null);

        $this->assertFalse($user->refresh()->hasVerifiedContactEmail());
    }

    public function test_account_routes_require_authentication(): void
    {
        $this->putJson('/api/account/email', ['email' => 'nope@example.com'])->assertUnauthorized();
        $this->postJson('/api/account/email/verify', ['code' => '123456'])->assertUnauthorized();
        $this->getJson('/api/account')->assertUnauthorized();
    }

    private function otherCode(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}