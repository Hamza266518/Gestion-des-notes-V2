<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\SemestrePublication;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The saisie grid used to be unable to enter the very first grade of a
 * sequence: NoteAdminController::index only returns existing rows, so an
 * ungraded sequence yielded no students and the page fell back to an empty
 * state. POST /admin/notes is what makes that first cell writable.
 */
class NoteCreatePathTest extends TestCase
{
    private User $admin;
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    private Sequence $sequence;
    private Controle $controle;
    /** @var \Illuminate\Support\Collection */
    private $publications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'note.create@sweep.test')->first()
            ?? User::create([
                'name'     => 'Note create admin',
                'email'    => 'note.create@sweep.test',
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
            'nom'              => 'UNITE NOTE CREATE TEST',
            'coefficient'      => 1,
            'nombre_controles' => 1,
            'numero_annee'     => $this->etudiant->groupe->niveau->numero,
            'semestre'         => 1,
            'ordre'            => 996,
            'is_active'        => true,
        ]);

        $this->sequence = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => 'SEQ NOTE CREATE TEST',
            'coefficient'      => 1,
            'nombre_controles' => 1,
        ]);

        $this->controle = Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => 1,
            'note_max'    => 20,
        ]);

        // Start from an unpublished semester so the write is allowed. Snapshot
        // the rows by content, not by id: the tests below create a publication
        // for this same (groupe, annee, type), and restoring the snapshot by id
        // would leave that new row behind next to the restored one.
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
        if (isset($this->controle)) {
            Note::where('controle_id', $this->controle->id)->delete();
            Controle::where('id', $this->controle->id)->delete();
        }
        if (isset($this->sequence)) {
            $this->sequence->delete();
        }
        if (isset($this->unite)) {
            $this->unite->delete();
        }

        // Remove anything the tests published for this group first, otherwise a
        // row created here would sit next to the restored snapshot.
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

    private function create(array $overrides = [])
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/admin/notes', array_merge([
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $this->controle->id,
                'valeur'      => 12,
            ], $overrides));
    }

    public function test_first_grade_of_an_ungraded_sequence_can_be_entered(): void
    {
        // The sequence starts with no Note at all, which is exactly the case the
        // saisie grid could not handle before.
        $this->assertSame(0, Note::where('controle_id', $this->controle->id)->count());

        $this->create(['valeur' => 12.5])->assertCreated();

        $note = Note::where('controle_id', $this->controle->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(12.5, (float) $note->valeur, 0.001);
        $this->assertFalse((bool) $note->est_absent);
    }

    public function test_absence_can_be_recorded_on_a_first_grade(): void
    {
        $this->create(['valeur' => null, 'est_absent' => true])->assertCreated();

        $note = Note::where('controle_id', $this->controle->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->firstOrFail();

        $this->assertTrue((bool) $note->est_absent);
        $this->assertEqualsWithDelta(0.0, (float) $note->valeur, 0.001, 'An absence is a zero');
    }

    public function test_creating_twice_does_not_duplicate_the_note(): void
    {
        $this->create(['valeur' => 10])->assertCreated();
        $this->create(['valeur' => 16])->assertCreated();

        $this->assertSame(1, Note::where('controle_id', $this->controle->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->count());

        $note = Note::where('controle_id', $this->controle->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->first();

        $this->assertEqualsWithDelta(16.0, (float) $note->valeur, 0.001);
    }

    public function test_grade_is_validated_against_the_control_scale(): void
    {
        // A control marked out of /10 must reject a value of 15.
        Controle::where('id', $this->controle->id)->update(['note_max' => 10]);

        $this->create(['valeur' => 15])->assertStatus(422);
        $this->assertSame(0, Note::where('controle_id', $this->controle->id)->count());

        $this->create(['valeur' => 8])->assertCreated();
    }

    /** @dataProvider badValues */
    public function test_invalid_grade_is_rejected($value): void
    {
        $this->create(['valeur' => $value])->assertStatus(422);
        $this->assertSame(0, Note::where('controle_id', $this->controle->id)->count());
    }

    public static function badValues(): array
    {
        return [
            'negative'   => [-1],
            'not a number' => ['abc'],
            'missing'    => [null],
        ];
    }

    public function test_published_semester_refuses_new_grades(): void
    {
        SemestrePublication::updateOrCreate(
            [
                'groupe_id'          => $this->etudiant->groupe_id,
                'annee_academique_id'=> $this->annee->id,
                'type'               => 'notes_s1',
            ],
            ['is_published' => true]
        );

        $this->create(['valeur' => 12])->assertStatus(403);
        $this->assertSame(0, Note::where('controle_id', $this->controle->id)->count());
    }

    public function test_unknown_ids_are_rejected(): void
    {
        $this->create(['etudiant_id' => 999999999])->assertStatus(422);
        $this->create(['controle_id' => 999999999])->assertStatus(422);
        $this->assertSame(0, Note::where('controle_id', $this->controle->id)->count());
    }
}