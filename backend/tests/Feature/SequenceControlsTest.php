<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing a sequence's "nombre de contrôles" must add/remove the real
 * Controle rows, and a refused reduction must leave the declared count and the
 * real contrôles in step (the count used to be saved first, so the screen
 * showed a number nothing matched).
 */
class SequenceControlsTest extends TestCase
{
    private ?Unite $unite = null;
    private ?Sequence $sequence = null;
    private ?User $admin = null;
    private ?User $studentUser = null;
    private ?Etudiant $etudiant = null;

    protected function tearDown(): void
    {
        if ($this->sequence) {
            Note::whereIn('controle_id', Controle::where('sequence_id', $this->sequence->id)->pluck('id'))->delete();
            Controle::where('sequence_id', $this->sequence->id)->delete();
            $this->sequence->delete();
        }

        if ($this->etudiant) {
            $this->etudiant->delete();
        }

        if ($this->studentUser) {
            $this->studentUser->delete();
        }

        if ($this->unite) {
            $this->unite->delete();
        }

        if ($this->admin) {
            $this->admin->tokens()->delete();
            $this->admin->delete();
        }

        parent::tearDown();
    }

    private function bootFixtures(): void
    {
        $filiere = Filiere::first();
        $this->assertNotNull($filiere, 'The test database must hold at least one filière');

        $this->admin = User::create([
            'name'     => 'Sequence controls admin',
            'username' => 'sequence.controls.admin',
            'password' => Hash::make('secret123'),
            'role'     => 'admin',
        ]);

        $this->unite = Unite::create([
            'filiere_id'   => $filiere->id,
            'nom'          => 'ZZ Sequence controls',
            'coefficient'  => 1,
            'numero_annee' => 1,
            'semestre'     => 1,
            'ordre'        => 999,
            'is_active'    => true,
        ]);
    }

    private function createSequence(int $controles): Sequence
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/sequences', [
                'unite_id'         => $this->unite->id,
                'nom'              => 'Séquence ZZ',
                'coefficient'      => $controles,
                'nombre_controles' => $controles,
            ]);

        $response->assertOk();

        return Sequence::findOrFail($response->json('data.id'));
    }

    private function updateCount(Sequence $sequence, int $controles, bool $force = false)
    {
        $payload = [
            'nom'              => $sequence->nom,
            'nombre_controles' => $controles,
        ];

        if ($force) {
            $payload['force'] = true;
        }

        return $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/sequences/{$sequence->id}", $payload);
    }

    public function test_changing_the_control_count_creates_and_removes_controls(): void
    {
        $this->bootFixtures();
        $this->sequence = $this->createSequence(2);

        $this->assertSame(2, Controle::where('sequence_id', $this->sequence->id)->count());

        $this->updateCount($this->sequence, 4)->assertOk();
        $this->assertSame(4, Controle::where('sequence_id', $this->sequence->id)->count());

        $this->updateCount($this->sequence, 1)->assertOk();
        $this->assertSame(1, Controle::where('sequence_id', $this->sequence->id)->count());
        $this->assertSame(1, (int) $this->sequence->fresh()->nombre_controles);
    }

    public function test_a_reduction_refused_because_of_notes_leaves_everything_untouched(): void
    {
        $this->bootFixtures();
        $this->sequence = $this->createSequence(3);

        $this->studentUser = User::create([
            'name'     => 'ZZ Candidat',
            'username' => 'sequence.controls.student',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->etudiant = Etudiant::create([
            'user_id'             => $this->studentUser->id,
            'groupe_id'           => \App\Models\Groupe::query()->value('id'),
            'annee_academique_id' => AnneeAcademique::query()->value('id'),
            'nom_prenom'          => 'ZZ Candidat',
            'cin'                 => 'ZZ-CIN-SEQCTRL',
            'numero_inscription'  => 'ZZ-INS-SEQCTRL',
        ]);

        $third = $this->sequence->controles()->where('numero', 3)->firstOrFail();
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $third->id,
            'valeur'      => 12,
        ]);

        $this->updateCount($this->sequence, 2)->assertStatus(409);

        $this->assertSame(3, (int) $this->sequence->fresh()->nombre_controles);
        $this->assertSame(3, Controle::where('sequence_id', $this->sequence->id)->count());
    }

    public function test_a_confirmed_reduction_removes_the_extra_controls_and_their_notes(): void
    {
        $this->bootFixtures();
        $this->sequence = $this->createSequence(3);

        $this->studentUser = User::create([
            'name'     => 'Sequence controls student',
            'username' => 'sequence.controls.student.force',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->etudiant = Etudiant::create([
            'user_id'             => $this->studentUser->id,
            'groupe_id'           => \App\Models\Groupe::query()->value('id'),
            'annee_academique_id' => AnneeAcademique::query()->value('id'),
            'nom_prenom'          => 'ZZ Candidat Force',
            'cin'                 => 'ZZ-CIN-SEQCTRL-F',
            'numero_inscription'  => 'ZZ-INS-SEQCTRL-F',
        ]);

        $third = Controle::where('sequence_id', $this->sequence->id)
            ->where('numero', 3)
            ->firstOrFail();

        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $third->id,
            'valeur'      => 12,
        ]);

        // force=true means the caller already confirmed the note loss.
        $this->updateCount($this->sequence, 2, true)->assertOk();

        $this->assertSame(2, (int) $this->sequence->fresh()->nombre_controles);
        $this->assertSame(2, Controle::where('sequence_id', $this->sequence->id)->count());
        $this->assertSame(0, Note::where('controle_id', $third->id)->count());
    }

    public function test_increasing_recreates_missing_numbers_instead_of_duplicating(): void
    {
        $this->bootFixtures();
        $this->sequence = $this->createSequence(4);

        // Simulate an earlier partial state: only 3 and 4 remain.
        Controle::where('sequence_id', $this->sequence->id)->whereIn('numero', [1, 2])->delete();

        $this->updateCount($this->sequence, 4)->assertOk();

        $numeros = Controle::where('sequence_id', $this->sequence->id)
            ->orderBy('numero')
            ->pluck('numero')
            ->map(fn ($n) => (int) $n)
            ->all();

        $this->assertSame([1, 2, 3, 4], $numeros);
        $this->assertSame(4, (int) $this->sequence->fresh()->nombre_controles);
    }
}
