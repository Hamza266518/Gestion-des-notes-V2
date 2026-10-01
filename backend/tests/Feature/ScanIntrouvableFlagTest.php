<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The scan results themselves carry an "introuvable" flag, so the frontend can
 * label a row without inferring anything, and the confirm endpoint refuses a
 * row with no student rather than silently dropping it.
 */
class ScanIntrouvableFlagTest extends TestCase
{
    private User $formateur;
    private AnneeAcademique $annee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formateur = User::where('email', 'flag.formateur@sweep.test')->first()
            ?? User::create([
                'name'     => 'Flag formateur',
                'email'    => 'flag.formateur@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'formateur',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();
    }

    public function test_confirm_rejects_a_row_without_a_student(): void
    {
        $controle = Controle::first();
        if (!$controle) {
            $this->markTestSkipped('No control fixture.');
        }

        $this->actingAs($this->formateur)
            ->postJson('/api/formateur/confirm', [
                'notes' => [
                    [
                        'etudiant_id' => null,
                        'controle_id' => $controle->id,
                        'note'        => 15,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notes.0.etudiant_id');

        $this->assertSame(
            0,
            Etudiant::where('nom_prenom', 'Introuvable Scan Fantome')->count(),
            'A rejected confirm must not create a student.'
        );
    }
}