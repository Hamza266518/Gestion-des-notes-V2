<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Unite;
use App\Models\User;
use App\Services\BulletinService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExamAbsenceTest extends TestCase
{
    private User $admin;
    private Etudiant $etudiant;
    private Unite $unite;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'exam.absence@sweep.test')->first()
            ?? User::create([
                'name'     => 'Exam absence admin',
                'email'    => 'exam.absence@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->etudiant = Etudiant::where('annee_academique_id', $annee->id)
            ->whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();

        $this->assertNotNull($this->etudiant);

        $this->unite = Unite::create([
            'filiere_id'       => $this->etudiant->groupe->niveau->filiere_id,
            'nom'              => 'UNITE EXAM ABSENCE TEST',
            'coefficient'      => 1,
            'nombre_controles' => 0,
            'numero_annee'     => $this->etudiant->groupe->niveau->numero,
            'semestre'         => 1,
            'ordre'            => 997,
            'is_active'        => true,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->unite)) {
            Examen::where('unite_id', $this->unite->id)->delete();
            $this->unite->delete();
        }

        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'examens'            => [],
            'unite_id'           => $this->unite->id,
            'bloc'               => 1,
            'type'               => 'theorique',
            'semestre'           => 1,
            'annee_academique_id'=> AnneeAcademique::where('is_current', true)->first()?->id
                ?? AnneeAcademique::first()->id,
        ], $overrides);
    }

    private function store(string $type, ?float $valeur, bool $estAbsent)
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/admin/examens/bulk', $this->payload([
                'type'    => $type,
                'examens' => [[
                    'etudiant_id' => $this->etudiant->id,
                    'valeur'      => $valeur,
                    'est_absent'  => $estAbsent,
                ]],
            ]));
    }

    public function test_exam_absence_is_stored_as_zero(): void
    {
        $this->store('theorique', null, true)->assertOk();

        $examen = Examen::where('unite_id', $this->unite->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->where('type', 'theorique')
            ->firstOrFail();

        $this->assertTrue((bool) $examen->est_absent);
        $this->assertEqualsWithDelta(0.0, (float) $examen->valeur, 0.001);
    }

    public function test_exam_grade_is_stored_without_absence(): void
    {
        $this->store('theorique', 14.5, false)->assertOk();

        $examen = Examen::where('unite_id', $this->unite->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->where('type', 'theorique')
            ->firstOrFail();

        $this->assertFalse((bool) $examen->est_absent);
        $this->assertEqualsWithDelta(14.5, (float) $examen->valeur, 0.001);
    }

    public function test_marking_absent_overwrites_the_grade_in_place(): void
    {
        $this->store('theorique', 17.0, false)->assertOk();
        $this->store('theorique', null, true)->assertOk();

        $rows = Examen::where('unite_id', $this->unite->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->where('type', 'theorique')
            ->get();

        $this->assertCount(1, $rows, 'Absence must update the row, not duplicate it');
        $this->assertTrue((bool) $rows->first()->est_absent);
        $this->assertEqualsWithDelta(0.0, (float) $rows->first()->valeur, 0.001);
    }

    public function test_clearing_absence_restores_the_grade(): void
    {
        $this->store('theorique', null, true)->assertOk();
        $this->store('theorique', 11.25, false)->assertOk();

        $examen = Examen::where('unite_id', $this->unite->id)
            ->where('etudiant_id', $this->etudiant->id)
            ->where('type', 'theorique')
            ->firstOrFail();

        $this->assertFalse((bool) $examen->est_absent);
        $this->assertEqualsWithDelta(11.25, (float) $examen->valeur, 0.001);
    }

    /**
     * School policy: an absence is a zero for calculation purposes. The "Absent"
     * badge exists only to tell it apart from a genuine zero.
     */
    public function test_exam_absence_counts_as_zero_in_the_bulletin_average(): void
    {
        $this->store('theorique', null, true)->assertOk();

        $bulletin = app(BulletinService::class)
            ->calculateBulletin($this->etudiant->id, AnneeAcademique::first()->id, 1);

        $unite = collect($bulletin['unites'])->firstWhere('id', $this->unite->id);

        $this->assertNotNull($unite);
        $this->assertEqualsWithDelta(0.0, (float) $unite['moyenne_theorique'], 0.001);
    }

    public function test_out_of_range_exam_grade_is_rejected(): void
    {
        $this->store('theorique', 25, false)->assertStatus(422);
        $this->assertSame(0, Examen::where('unite_id', $this->unite->id)->count());
    }
}