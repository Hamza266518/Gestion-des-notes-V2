<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\SemestrePublication;
use App\Models\StageNote;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The "Saisie examens" screen shows one column per Stage service. Those marks
 * live in `stage_notes`, one per (student, service); this covers the endpoints
 * that feed the grid and the hand-typed fallback (including values the printed
 * exam grid cannot produce, like 14.46).
 */
class StageExamNoteTest extends TestCase
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

        $this->admin = User::where('email', 'stage.exam@sweep.test')->first()
            ?? User::create([
                'name'     => 'Stage exam admin',
                'email'    => 'stage.exam@sweep.test',
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
            'nombre_controles' => 3,
            'numero_annee'     => $this->etudiant->groupe->niveau->numero,
            'semestre'         => 1,
            'ordre'            => 996,
            'is_active'        => true,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->controles[$i] = Controle::create([
                'unite_id' => $this->unite->id,
                'numero'   => $i,
                'nom'      => 'Service ' . $i,
                'note_max' => 20,
            ]);
        }

        // Start unpublished so writes are allowed, and restore afterwards.
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
            StageNote::whereIn('controle_id', $ids)->delete();
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

    public function test_exam_marks_are_listed_per_service(): void
    {
        StageNote::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[1]->id,
            'type'        => StageNote::EXAMEN_TYPE,
            'valeur'      => 13,
        ]);
        StageNote::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[3]->id,
            'type'        => StageNote::EXAMEN_TYPE,
            'valeur'      => 15,
        ]);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/stage/notes?' . http_build_query([
            'unite_id'  => $this->unite->id,
            'groupe_id' => $this->etudiant->groupe_id,
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('controle_id')->all();

        $this->assertContains($this->controles[1]->id, $ids);
        $this->assertContains($this->controles[3]->id, $ids);
        $this->assertNotContains($this->controles[2]->id, $ids);
    }

    public function test_an_exam_mark_can_be_typed_per_service_with_two_decimals(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/stage/notes', [
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $this->controles[2]->id,
                'valeur'      => 14.46,
            ])
            ->assertOk();

        $note = StageNote::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $this->controles[2]->id)
            ->where('type', StageNote::EXAMEN_TYPE)
            ->firstOrFail();

        $this->assertEqualsWithDelta(14.46, (float) $note->valeur, 0.001);
        $this->assertFalse((bool) $note->est_absent);
    }

    public function test_saving_again_updates_in_place(): void
    {
        $payload = [
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controles[1]->id,
            'valeur'      => 10,
        ];

        $this->actingAs($this->admin)->postJson('/api/admin/stage/notes', $payload)->assertOk();
        $this->actingAs($this->admin)->postJson('/api/admin/stage/notes', array_merge($payload, ['valeur' => 17]))->assertOk();

        $this->assertSame(1, StageNote::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $this->controles[1]->id)
            ->count());
        $this->assertEqualsWithDelta(17.0, (float) StageNote::where('controle_id', $this->controles[1]->id)
            ->where('etudiant_id', $this->etudiant->id)->value('valeur'), 0.001);
    }

    public function test_an_absent_exam_is_stored_as_zero(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/stage/notes', [
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $this->controles[3]->id,
                'valeur'      => null,
                'est_absent'  => true,
            ])
            ->assertOk();

        $note = StageNote::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $this->controles[3]->id)
            ->firstOrFail();

        $this->assertTrue((bool) $note->est_absent);
        $this->assertSame(0.0, (float) $note->valeur);
    }

    public function test_a_control_outside_a_stage_unit_is_refused(): void
    {
        $other = Unite::create([
            'filiere_id'   => $this->etudiant->groupe->niveau->filiere_id,
            'nom'          => 'Sciences infirmières',
            'coefficient'  => 2,
            'numero_annee' => $this->etudiant->groupe->niveau->numero,
            'semestre'     => 1,
            'ordre'        => 995,
            'is_active'    => true,
        ]);
        $controle = Controle::create([
            'unite_id' => $other->id,
            'numero'   => 1,
            'nom'      => 'Contrôle 1',
            'note_max' => 20,
        ]);

        try {
            $this->actingAs($this->admin)
                ->postJson('/api/admin/stage/notes', [
                    'etudiant_id' => $this->etudiant->id,
                    'controle_id' => $controle->id,
                    'valeur'      => 12,
                ])
                ->assertStatus(422);
        } finally {
            StageNote::where('controle_id', $controle->id)->delete();
            $controle->delete();
            $other->delete();
        }
    }
}
