<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\StageNote;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\MoyenneService;
use Tests\TestCase;

/**
 * A Stage unit is sequence-free and its controls are the "services". It must
 * only be counted once every service carries BOTH its marks: the Évaluation
 * note (in `notes`) and the Examen mark (in `stage_notes`). While any mark is
 * missing the unit is excluded from the averages instead of being dragged down
 * by implicit zeros. This locks that rule for the unit average and the
 * transcript.
 */
class StageAveragingTest extends TestCase
{
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    private array $created = ['unit' => false, 'controles' => []];

    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->etudiant = Etudiant::where('annee_academique_id', $this->annee->id)
            ->whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();

        $this->assertNotNull($this->etudiant, 'Fixtures need one student with a groupe/niveau.');

        $this->ensureStageUnit();
    }

    protected function tearDown(): void
    {
        if (isset($this->etudiant, $this->unite)) {
            $ids = Controle::where('unite_id', $this->unite->id)->pluck('id');
            if ($ids->isNotEmpty()) {
                Note::where('etudiant_id', $this->etudiant->id)
                    ->whereIn('controle_id', $ids)
                    ->delete();
                StageNote::where('etudiant_id', $this->etudiant->id)
                    ->whereIn('controle_id', $ids)
                    ->delete();
            }
        }

        foreach ($this->created['controles'] as $id) {
            Controle::where('id', $id)->delete();
        }

        if (!empty($this->created['unit']) && isset($this->unite)) {
            $this->unite->delete();
        }

        parent::tearDown();
    }

    private function ensureStageUnit(): void
    {
        $filiereId = $this->etudiant->groupe->niveau->filiere_id;
        $anneeNum  = $this->etudiant->groupe->niveau->numero;

        $unite = Unite::where('nom', 'Stage')
            ->where('filiere_id', $filiereId)
            ->where('numero_annee', $anneeNum)
            ->where('is_active', true)
            ->first();

        if (!$unite) {
            $unite = Unite::create([
                'filiere_id'   => $filiereId,
                'nom'          => 'Stage',
                'coefficient'  => 4,
                'numero_annee' => $anneeNum,
                'semestre'     => 2,
                'ordre'        => 999,
                'is_active'    => true,
            ]);
            $this->created['unit'] = true;
        }

        // Guarantee at least two "services" to leave one ungraded.
        if (Controle::where('unite_id', $unite->id)->count() < 2) {
            for ($n = 1; $n <= 3; $n++) {
                $ctrl = Controle::create([
                    'unite_id' => $unite->id,
                    'numero'   => $n,
                    'nom'      => 'Contrôle ' . $n,
                    'note_max' => 20,
                ]);
                $this->created['controles'][] = $ctrl->id;
            }
        }

        $this->unite = $unite;
    }

    /** @return \Illuminate\Support\Collection<int, Controle> */
    private function stageControles()
    {
        return Controle::where('unite_id', $this->unite->id)->orderBy('numero')->get();
    }

    private function clearStudentMarks(): void
    {
        $ids = $this->stageControles()->pluck('id');
        Note::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', $ids)
            ->delete();
        StageNote::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', $ids)
            ->delete();
    }

    /** The service's Évaluation mark (in `notes`). */
    private function mark(int $controleId, float $valeur): void
    {
        Note::updateOrCreate(
            ['etudiant_id' => $this->etudiant->id, 'controle_id' => $controleId],
            ['valeur' => $valeur, 'est_absent' => false]
        );
    }

    /** The service's Examen mark (in `stage_notes`). */
    private function markExam(int $controleId, float $valeur): void
    {
        StageNote::updateOrCreate(
            [
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $controleId,
                'type'        => StageNote::EXAMEN_TYPE,
            ],
            ['valeur' => $valeur, 'est_absent' => false]
        );
    }

    private function uniteMoyenne(): ?float
    {
        return app(MoyenneService::class)->moyenneUnite($this->etudiant->id, $this->unite->id);
    }

    /** The Stage's MPCC value: the average of its services' Évaluation marks. */
    private function stageEvaluationMoyenne(): ?float
    {
        return app(MoyenneService::class)->moyenneStageEvaluation($this->etudiant->id, $this->unite);
    }

    /** The Stage's Pratique value: the average of its services' Examen marks. */
    private function stageExamMoyenne(): ?float
    {
        return app(MoyenneService::class)->moyenneStageExamen($this->etudiant->id, $this->unite);
    }

    private function bulletinUnit(): ?array
    {
        $bulletin = app(BulletinService::class)->calculateBulletin($this->etudiant->id, $this->annee->id);

        return collect($bulletin['unites'])->firstWhere('id', $this->unite->id);
    }

    private function stageRow(array $unit): ?array
    {
        return collect($unit['sequences'])->first(fn ($s) => $s['id'] === null);
    }

    public function test_stage_combined_average_waits_for_every_service_to_have_both_marks(): void
    {
        $ids = $this->stageControles()->pluck('id')->all();
        $this->assertGreaterThanOrEqual(2, count($ids), 'Need at least two services to leave one blank.');
        $this->clearStudentMarks();

        // Every service graded 10 on both sides -> plain average of 10.
        foreach ($ids as $id) {
            $this->mark($id, 10);
            $this->markExam($id, 10);
        }
        $this->assertSame(10.0, $this->uniteMoyenne());

        // Drop one service's exam mark: the combined unit average drops out
        // entirely, but the bulletin now shows each side on its own.
        $last = (int) end($ids);
        StageNote::where('etudiant_id', $this->etudiant->id)->where('controle_id', $last)->delete();

        $this->assertNull(
            $this->uniteMoyenne(),
            'A Stage with a missing exam mark must not produce a combined unit average'
        );

        $unit = $this->bulletinUnit();
        $this->assertNotNull($unit, 'The Stage unit must still appear in the transcript.');
        $this->assertSame(10.0, $unit['moyenne_cc'], 'The CC side is the Évaluation average.');
        $this->assertSame(10.0, $unit['moyenne_pratique'], 'The Pratique side is the Examen average.');
        $this->assertNotNull(
            $this->stageRow($unit)['moyenneSeq'],
            'The Stage row shows the marks entered so far instead of staying blank.'
        );

        // Filling the last exam mark makes the combined average whole again.
        $this->markExam($last, 10);

        $this->assertSame(10.0, $this->uniteMoyenne());
    }

    public function test_stage_average_combines_the_two_marks_of_each_service(): void
    {
        $ids = $this->stageControles()->pluck('id')->all();
        $this->clearStudentMarks();

        // Évaluation 12 everywhere, Examen 8 everywhere -> (12 + 8) / 2 = 10.
        foreach ($ids as $id) {
            $this->mark($id, 12);
            $this->markExam($id, 8);
        }

        $this->assertSame(10.0, $this->uniteMoyenne());
    }

    public function test_empty_stage_is_excluded_from_the_unit_average(): void
    {
        $this->clearStudentMarks();

        $this->assertNull(
            $this->uniteMoyenne(),
            'A Stage with no note at all must stay out of the averages.'
        );
    }

    public function test_mpcc_shows_the_evaluation_average_before_the_exams_are_entered(): void
    {
        $ids = $this->stageControles()->pluck('id')->values()->all();
        $this->assertGreaterThanOrEqual(2, count($ids));
        $this->clearStudentMarks();

        // Only the Évaluation side is filled -> the CC sheet already shows it.
        $this->mark($ids[0], 12);
        $this->mark($ids[1], 16);
        $expected = round((12 + 16) / 2, 2);

        $this->assertSame($expected, $this->stageEvaluationMoyenne());

        // The Examen side must never change the MPCC figure.
        $this->markExam($ids[0], 5);
        $this->markExam($ids[1], 5);
        $this->assertSame($expected, $this->stageEvaluationMoyenne());

        // The unit average, however, stays gated until every service has both.
        $this->assertNull(
            $this->uniteMoyenne(),
            'The unit average must still require both marks on every service.'
        );
    }

    public function test_mpcc_evaluation_average_is_null_without_any_evaluation(): void
    {
        $this->clearStudentMarks();

        $this->assertNull(
            $this->stageEvaluationMoyenne(),
            'A Stage with no Évaluation mark at all must show nothing in MPCC.'
        );
    }

    public function test_pratique_uses_the_exam_marks_before_every_service_is_graded(): void
    {
        $ids = $this->stageControles()->pluck('id')->values()->all();
        $this->assertGreaterThanOrEqual(2, count($ids));
        $this->clearStudentMarks();

        // Only the Examen side is filled -> the Pratique sheet already shows it.
        $this->markExam($ids[0], 14);
        $this->markExam($ids[1], 18);
        $expected = round((14 + 18) / 2, 2);

        $this->assertSame($expected, $this->stageExamMoyenne());

        // The Évaluation side must never change the Pratique figure.
        $this->mark($ids[0], 2);
        $this->mark($ids[1], 2);
        $this->assertSame($expected, $this->stageExamMoyenne());
    }

    public function test_pratique_is_null_without_any_exam(): void
    {
        $this->clearStudentMarks();

        $this->assertNull(
            $this->stageExamMoyenne(),
            'A Stage with no Examen mark at all must show nothing in the Pratique table.'
        );
    }

    public function test_bulletin_splits_the_stage_evaluation_into_cc_and_the_exam_into_pratique(): void
    {
        $ids = $this->stageControles()->pluck('id')->all();
        $this->clearStudentMarks();

        foreach ($ids as $id) {
            $this->mark($id, 12);      // Évaluation -> CC
            $this->markExam($id, 16);  // Examen -> Pratique
        }

        $unit = $this->bulletinUnit();
        $this->assertNotNull($unit, 'The Stage unit must appear in the transcript.');
        $this->assertSame(12.0, $unit['moyenne_cc'], 'The Stage CC is its Évaluation average.');
        $this->assertSame(16.0, $unit['moyenne_pratique'], 'The Stage Pratique is its Examen average.');
    }
}
