<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsernameLoginTest extends TestCase
{
    private ?int $userId = null;

    protected function tearDown(): void
    {
        if ($this->userId !== null) {
            User::where('id', $this->userId)->delete();
            $this->userId = null;
        }

        parent::tearDown();
    }

    private function makeUser(string $username = 'login.tester', string $role = 'etudiant'): User
    {
        $user = User::create([
            'name'     => 'Login Tester',
            'username' => $username,
            'password' => Hash::make('secret1234'),
            'role'     => $role,
        ]);

        $this->userId = $user->id;

        return $user;
    }

    public function test_login_succeeds_with_the_username(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'secret1234',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.username', $user->username)
            ->assertJsonPath('data.role', 'etudiant')
            ->assertJsonStructure(['data' => ['token', 'role', 'name', 'username']]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_is_case_insensitive_and_trims_the_username(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/auth/login', [
            'username' => '  ' . strtoupper($user->username) . ' ',
            'password' => 'secret1234',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_login_never_exposes_the_legacy_email_field(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'secret1234',
        ])->assertOk();

        $this->assertArrayNotHasKey('email', $response->json('data'));
        $this->assertNull($user->fresh()->email, 'Aucune adresse email synthétique ne doit être stockée');
    }

    public function test_login_rejects_an_email_style_identifier(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/login', [
            'username' => 'login.tester@ifp.ma',
            'password' => 'secret1234',
        ])->assertStatus(401)->assertJsonPath('success', false);
    }

    public function test_login_fails_with_a_wrong_password_and_stays_generic(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'username' => $user->username,
            'password' => 'wrong-password',
        ])->assertStatus(401)->assertJsonPath('success', false);

        $this->assertSame('Identifiant ou mot de passe incorrect', $response->json('message'));
    }

    public function test_login_fails_for_an_unknown_username_with_the_same_message(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'username' => 'nobody.here',
            'password' => 'secret1234',
        ])->assertStatus(401);

        $this->assertSame('Identifiant ou mot de passe incorrect', $response->json('message'));
    }

    public function test_login_requires_a_username_and_a_password(): void
    {
        $this->postJson('/api/auth/login', ['password' => 'secret1234'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');

        $this->postJson('/api/auth/login', ['username' => 'someone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_me_returns_the_username_and_the_optional_contact_email(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', $user->username);

        $this->assertNull($response->json('data.contact_email'));
        $this->assertNull($response->json('data.contact_email_verified_at'));
    }

    public function test_the_schema_makes_username_mandatory_and_email_optional(): void
    {
        $columns = collect(Schema::getColumns('users'))->keyBy('name');

        $this->assertFalse($columns['username']['nullable'], 'username est obligatoire');
        $this->assertTrue($columns['email']['nullable'], 'email accepte désormais la valeur nulle');
        $this->assertTrue($columns['contact_email']['nullable']);
        $this->assertTrue(Schema::hasTable('auth_codes'));
        $this->assertFalse(
            Schema::hasTable('etudiant_password_resets'),
            'La table de l\'ancienne récupération doit être supprimée'
        );
    }

    public function test_no_synthetic_ifp_address_survives(): void
    {
        $this->assertSame(
            0,
            DB::table('users')->where('email', 'like', '%@ifp.ma')->count(),
            'Toutes les adresses synthétiques @ifp.ma doivent avoir été supprimées'
        );
    }
}