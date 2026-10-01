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

class NoteWritePathTest extends TestCase
{
    private User $admin;
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    private Sequence $sequence;
    private int $controleId;
    /** @var \Illuminate\Support\Collection */
    private $publications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'sweep.admin@sweep.test')->first()
            ?? User::create([
                'name'     => 'Sweep admin',
                'email'    => 'sweep.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->etudiant = Etudiant::where('annee_academique_id', $this->annee->id)
            ->whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();

        $this->assertNotNull($this->etudiant);

        $filiereId    = $this->etudiant->groupe->niveau->filiere_id;
        $niveauNumero = $this->etudiant->groupe->niveau->numero;

        $this->unite = Unite::create([
            'filiere_id'       => $filiereId,
            'nom'              => 'UNITE NOTE WRITE TEST',
            'coefficient'      => 1,
            'nombre_controles' => 1,
            'numero_annee'     => $niveauNumero,
            'semestre'         => 1,
            'ordre'            => 998,
            'is_active'        => true,
        ]);

        $this->sequence = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => 'SEQ NOTE WRITE TEST',
            'coefficient'      => 1,
            'nombre_controles' => 1,
        ]);

        $this->controleId = Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => 1,
            'type'        => 'cc',
            'note_max'    => 20,
        ])->id;

        // A published semester would mask the validation behaviour. Remember it
        // so tearDown can put the seeded state back.
        $this->publications = SemestrePublication::where('groupe_id', $this->etudiant->groupe_id)
            ->where('annee_academique_id', $this->annee->id)
            ->get();
        SemestrePublication::whereIn('id', $this->publications->pluck('id'))->delete();
    }

    protected function tearDown(): void
    {
        // Only remove what this test created. Notes are keyed by controle, so
        // scoping to the test controle keeps every other grade of this student.
        if (isset($this->controleId)) {
            Note::where('controle_id', $this->controleId)->delete();
            Controle::where('id', $this->controleId)->delete();
        }
        if (isset($this->sequence)) {
            $this->sequence->delete();
        }
        if (isset($this->unite)) {
            $this->unite->delete();
        }

        foreach ($this->publications ?? [] as $publication) {
            SemestrePublication::updateOrCreate(
                ['id' => $publication->id],
                [
                    'groupe_id'          => $publication->groupe_id,
                    'annee_academique_id' => $publication->annee_academique_id,
                    'type'               => $publication->type,
                    'is_published'       => $publication->is_published,
                    'published_at'       => $publication->published_at,
                ]
            );
        }

        parent::tearDown();
    }

    private function note(float $valeur = 10): Note
    {
        return Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controleId,
            'valeur'      => $valeur,
        ]);
    }

    public function test_valid_grade_is_saved(): void
    {
        $note = $this->note();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/notes/{$note->id}", ['valeur' => 15.5]);

        $response->assertOk();
        $this->assertEqualsWithDelta(15.5, (float) $note->fresh()->valeur, 0.001);
    }

    /** @dataProvider invalidGrades */
    public function test_invalid_grade_is_rejected_as_422($value): void
    {
        $note = $this->note();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/notes/{$note->id}", ['valeur' => $value]);

        $response->assertStatus(422);
        $this->assertEqualsWithDelta(10.0, (float) $note->fresh()->valeur, 0.001, 'Value must not change');
    }

    public static function invalidGrades(): array
    {
        return [
            'above 20'  => [25],
            'negative'  => [-1],
            'not a number' => ['abc'],
        ];
    }

    public function test_missing_grade_is_404_not_500(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson('/api/admin/notes/999999999', ['valeur' => 10]);

        $response->assertStatus(404);
    }

    public function test_grade_cannot_exceed_the_control_scale(): void
    {
        // A control marked out of /10 must reject a value of 15.
        Controle::where('id', $this->controleId)->update(['note_max' => 10]);
        $note = $this->note();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/notes/{$note->id}", ['valeur' => 15])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->putJson("/api/admin/notes/{$note->id}", ['valeur' => 8])
            ->assertOk();

        $this->assertEqualsWithDelta(8.0, (float) $note->fresh()->valeur, 0.001);
    }
}
