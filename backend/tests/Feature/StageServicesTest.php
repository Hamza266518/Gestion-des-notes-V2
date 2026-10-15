<?php

namespace Tests\Feature;

use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Stage grille's "service" dropdown is not a fixed list: it mirrors the
 * Stage unit's own controls, which the admin names after the real departments.
 * The page builds its select from this endpoint, so the contract is locked here.
 *
 * Cleanup deletes only the rows this test created, by primary key, because the
 * suite shares one database with the shipped fixtures.
 */
class StageServicesTest extends TestCase
{
    private Etudiant $etudiant;
    private User $admin;
    /** @var int[] Only the controls this test created. */
    private array $createdControles = [];
    /** @var int[] Only the units this test created. */
    private array $createdUnites = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->etudiant = Etudiant::whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();
        $this->assertNotNull($this->etudiant, 'Fixtures need one student with a groupe/niveau.');

        $this->admin = User::where('email', 'stage.services@test.local')->first()
            ?? User::create([
                'name'     => 'Stage services admin',
                'email'    => 'stage.services@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->ensureStageUnit();
    }

    protected function tearDown(): void
    {
        Controle::whereIn('id', $this->createdControles)->delete();
        Unite::whereIn('id', $this->createdUnites)->delete();
        parent::tearDown();
    }

    /** The endpoint resolves the Stage unit by name; the fixture must own one. */
    private function ensureStageUnit(): void
    {
        $niveau = $this->etudiant->groupe->niveau;

        $unite = Unite::where('nom', 'Stage')
            ->where('filiere_id', $niveau->filiere_id)
            ->where('numero_annee', $niveau->numero)
            ->where('is_active', true)
            ->first();

        if (!$unite) {
            $unite = Unite::create([
                'filiere_id'   => $niveau->filiere_id,
                'nom'          => 'Stage',
                'coefficient'  => 4,
                'numero_annee' => $niveau->numero,
                'semestre'     => 1,
                'ordre'        => 99,
                'is_active'    => true,
            ]);
            $this->createdUnites[] = $unite->id;
        }

        foreach ([1, 2] as $numero) {
            $exists = Controle::where('unite_id', $unite->id)->where('numero', $numero)->exists();
            if (!$exists) {
                $controle = Controle::create([
                    'unite_id' => $unite->id,
                    'numero'   => $numero,
                    'nom'      => "Contrôle {$numero}",
                    'note_max' => 20,
                ]);
                $this->createdControles[] = $controle->id;
            }
        }
    }

    /** The controls the endpoint is expected to return, in display order. */
    private function stageControles()
    {
        $niveau = $this->etudiant->groupe->niveau;

        return Controle::whereIn('unite_id', Unite::where('nom', 'Stage')
                ->where('filiere_id', $niveau->filiere_id)
                ->where('numero_annee', $niveau->numero)
                ->where('is_active', true)
                ->pluck('id'))
            ->orderBy('numero')
            ->get();
    }

    public function test_it_lists_the_stage_controls_as_services(): void
    {
        $controles = $this->stageControles();
        $this->assertNotEmpty($controles, 'The Stage unit must own controls.');

        $original = [];
        try {
            // Rename so the assertion proves the endpoint reads the controls
            // rather than returning a hard-coded list of departments.
            foreach ($controles as $i => $controle) {
                $original[$controle->id] = $controle->nom;
                $controle->update(['nom' => "Service test {$i}"]);
            }

            $res = $this->actingAs($this->admin, 'sanctum')
                ->getJson('/api/admin/stage/services?etudiant_id=' . $this->etudiant->id);

            $res->assertOk();
            $expected = $controles->values()->map(fn ($c, $i) => "Service test {$i}")->all();
            $this->assertSame($expected, array_column($res->json('data'), 'nom'));
        } finally {
            foreach ($original as $id => $nom) {
                Controle::where('id', $id)->update(['nom' => $nom]);
            }
        }
    }

    public function test_an_unknown_student_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/stage/services?etudiant_id=999999999')
            ->assertStatus(422);
    }
}
