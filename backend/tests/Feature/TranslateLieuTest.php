<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The /admin/translate-lieu endpoint asks Gemini for the Arabic name of a
 * French place of birth, used when the static city dictionary has no entry.
 */
class TranslateLieuTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'translate.lieu@test.local')->first()
            ?? User::create([
                'name'     => 'Translate lieu admin',
                'email'    => 'translate.lieu@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
    }

    private function geminiSansCle(): object
    {
        return new class {
            public string $asked = '';

            public function translateLieu(string $frenchPlace): string
            {
                $this->asked = $frenchPlace;

                return $frenchPlace === 'Fes' ? 'فاس' : '';
            }
        };
    }

    public function test_endpoint_returns_the_arabic_place(): void
    {
        $gemini = $this->geminiSansCle();
        $this->app->instance(GeminiService::class, $gemini);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/translate-lieu', ['lieu' => 'Fes'])
            ->assertOk()
            ->assertJsonPath('data.lieu_ar', 'فاس');

        $this->assertSame('Fes', $gemini->asked);
    }

    public function test_unknown_place_is_returned_as_empty_string(): void
    {
        $gemini = $this->geminiSansCle();
        $this->app->instance(GeminiService::class, $gemini);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/translate-lieu', ['lieu' => 'Village inconnu'])
            ->assertOk()
            ->assertJsonPath('data.lieu_ar', '');
    }

    public function test_missing_lieu_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/translate-lieu', [])
            ->assertStatus(422);
    }
}