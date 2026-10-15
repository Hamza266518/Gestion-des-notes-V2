<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Stage units carry their controls directly on the unit (no sequence). The
 * saisie grid must list them, and each service control is the service's
 * Évaluation mark, writable by hand; the Examen mark lives in `stage_notes`.
 */
class NoteDirectControleTest extends TestCase
{
    private User $admin;
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    /** @var array<int, Controle> */
    private array $controles = [];
    /** @var \Illuminate\Support\Collection */
    private $publications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'note.direct@sweep.test')->first()
            ?? User::create([
                'name'     => 'Note direct admin',
                'email'    => 'note.direct@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->etudiant = Etudiant::where('annee_academique_id', $this->annee->id)
            ->whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();

        $this->assertNotNull($this->etudiant);

        $this->unite = Unite::create([
            'filiere_id'       => $this->etudiant->groupe->niveau->filiere_id,
            'nom'              => 'Stage',
            'coefficient'      => 4,
            'nombre_controles' => 4,
            'numero_annee'     => $this->etudiant->groupe->niveau->numero,
            'semestre'         => 1,
            'ordre'            => 997,
            'is_active'        => true,
        ]);

        for ($i = 1; $i <= 4; $i++) {
            $this->controles[$i] = Controle::create([
                'unite_id' => $this->unite->id,
                'numero'   => $i,
                'nom'      => 'Contrôle ' . $i,
                'note_max' => 20,
            ]);
        }

        // Start unpublished so a write is allowed, and restore afterwards.
        $this->publications = SemestrePublication::where('groupe_id', $this->etudiant->groupe_id)
            ->where('annee_academique_id', $this->annee->id)
            ->get()
            ->map(fn ($p) => [
                'groupe_id'           => $p->groupe_id,
                'annee_academique_id' => $p->annee_academique_id,
                'type'                => $p->type,
                'is_published'        => $p->is_published,
                'published_at'        => $p->published_at?->toDateTimeString(),
            ])
            ->all();

        SemestrePublication::where('groupe_id', $this->etudiant->groupe_id)
            ->where('annee_academique_id', $this->annee->id)
            ->delete();
    }

    protected function tearDown(): void
    {
        $ids = array_map(fn ($c) => $c->id, $this->controles);
        if ($ids) {
            Note::whereIn('controle_id', $ids)->delete();
            Controle::whereIn('id', $ids)->delete();
        }
        if (isset($this->unite)) {
            $this->unite->delete();
        }

        SemestrePublication::where('groupe_id', $this->etudiant->groupe_id)
            ->where('annee_academique_id', $this->annee->id)
            ->delete();

        foreach ($this->publications ?? [] as $publication) {
            SemestrePublication::updateOrCreate(
                [
                    'groupe_id'           => $publication['groupe_id'],
                    'annee_academique_id' => $publication['annee_academique_id'],
                    'type'                => $publication['type'],
                ],
                [
                    'is_published' => $publication['is_published'],
                    'published_at' => $publication['published_at'],
                ]
            );
        }

        parent::tearDown();
    }

    public function test_index_lists_direct_control_notes_by_unite(): void
    {
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[1]->id,
            'valeur'      => 12,
        ]);
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[3]->id,
            'valeur'      => 14,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/notes?' . http_build_query([
            'unite_id'            => $this->unite->id,
            'groupe_id'           => $this->etudiant->groupe_id,
            'annee_academique_id' => $this->annee->id,
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('controle_id')->all();

        $this->assertContains($this->controles[1]->id, $ids);
        $this->assertContains($this->controles[3]->id, $ids);
    }

    public function test_remaining_control_can_be_typed_in(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/notes', [
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $this->controles[3]->id,
                'valeur'      => 16,
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(16.0, (float) Note::where('controle_id', $this->controles[3]->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->firstOrFail()->valeur, 0.001);
    }

    /**
     * Every Stage service control carries the service's Évaluation mark and is
     * editable by hand from the saisie grid; the Examen mark lives in
     * `stage_notes` and is never touched here.
     */
    /** @dataProvider serviceControls */
    public function test_stage_service_controls_accept_a_typed_note(int $numero): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/notes', [
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $this->controles[$numero]->id,
                'valeur'      => 12,
            ])
            ->assertCreated();

        $this->assertEqualsWithDelta(12.0, (float) Note::where('controle_id', $this->controles[$numero]->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->firstOrFail()->valeur, 0.001);
    }

    public static function serviceControls(): array
    {
        return ['service 1' => [1], 'service 2' => [2], 'service 3' => [3], 'service 4' => [4]];
    }

    public function test_a_stage_service_note_can_be_updated(): void
    {
        $note = Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[1]->id,
            'valeur'      => 12,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/notes/{$note->id}", ['valeur' => 18])
            ->assertOk();

        $this->assertEqualsWithDelta(18.0, (float) $note->fresh()->valeur, 0.001);
    }
}
