<?php

namespace Tests\Unit;

use App\Services\StageService;
use Tests\TestCase;

class StageServiceTest extends TestCase
{
    protected StageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StageService(app(\App\Services\MoyenneService::class));
    }

    public function test_all_zero_is_a_real_mark_not_an_empty_grid(): void
    {
        $zero = [
            'comportement'    => array_fill(0, 5, 0),
            'aptitudes'       => array_fill(0, 10, 0),
            'eval_pratique_1' => '0',
            'eval_pratique_2' => '0',
        ];
        // A genuinely zeroed Stage must be recorded as 0, not dropped.
        $this->assertSame(0.0, $this->service->computeEvaluationMark($zero));

        $exam = ['lignes' => array_map(fn() => ['note' => '0'], range(1, 7))];
        $this->assertSame(0.0, $this->service->computeExamenMark($exam));
    }

    public function test_unfilled_grids_produce_no_mark(): void
    {
        // Untouched grids come back from the form as nulls / empty strings.
        $this->assertNull($this->service->computeEvaluationMark([
            'comportement'    => array_fill(0, 5, null),
            'aptitudes'       => array_fill(0, 10, ''),
            'eval_pratique_1' => '',
            'eval_pratique_2' => null,
        ]));
        $this->assertNull($this->service->computeEvaluationMark(['comportement' => [], 'aptitudes' => []]));
        $this->assertNull($this->service->computeEvaluationMark(null));
    }

    public function test_evaluation_grid_matches_the_printed_formula(): void
    {
        // All 15 criteria maxed (20 + 40) and both practical evaluations maxed
        // (20 + 20) => (60 + 40) / 5 = 20.
        $data = [
            'comportement'    => array_fill(0, 5, 4),
            'aptitudes'       => array_fill(0, 10, 4),
            'eval_pratique_1' => '20',
            'eval_pratique_2' => '20',
        ];
        $this->assertSame(20.0, $this->service->computeEvaluationMark($data));
    }

    public function test_evaluation_grid_with_partial_scores(): void
    {
        // comportement 3,3,3,3,3 = 15 ; aptitudes 3 x10 = 30 ; pratiques 10 + 8
        // (15 + 30 + 18) / 5 = 63 / 5 = 12.60
        $data = [
            'comportement'    => array_fill(0, 5, 3),
            'aptitudes'       => array_fill(0, 10, 3),
            'eval_pratique_1' => '10',
            'eval_pratique_2' => '8',
        ];
        $this->assertSame(12.6, $this->service->computeEvaluationMark($data));
    }

    public function test_evaluation_grid_can_be_scored_without_practical_marks(): void
    {
        // Score(1) = 15 + 30 = 45 ; no practical evaluation yet.
        // (45 + 0) / 5 = 9.00
        $data = [
            'comportement'    => array_fill(0, 5, 3),
            'aptitudes'       => array_fill(0, 10, 3),
            'eval_pratique_1' => '',
            'eval_pratique_2' => '',
        ];
        $this->assertSame(9.0, $this->service->computeEvaluationMark($data));
    }

    public function test_exam_grid_sums_its_seven_lines(): void
    {
        // Each line is capped at its own barème: 1+2+2+10+1+1+3 = 20.
        $full = ['lignes' => [
            ['note' => '1'], ['note' => '2'], ['note' => '2'], ['note' => '10'],
            ['note' => '1'], ['note' => '1'], ['note' => '3'],
        ]];
        $this->assertSame(20.0, $this->service->computeExamenMark($full));
    }

    public function test_exam_grid_is_capped_by_the_bareme(): void
    {
        // Every line typed far above its barème must still total 20.
        $blown = ['lignes' => array_map(fn() => ['note' => '20'], range(1, 7))];
        $this->assertSame(20.0, $this->service->computeExamenMark($blown));

        // Half marks on the weighted line only.
        $partial = ['lignes' => [
            ['note' => '1'], ['note' => '2'], ['note' => '2'], ['note' => '5'],
            ['note' => '1'], ['note' => '1'], ['note' => '3'],
        ]];
        $this->assertSame(15.0, $this->service->computeExamenMark($partial));
    }

    public function test_empty_exam_grid_produces_no_mark(): void
    {
        $this->assertNull($this->service->computeExamenMark(null));
        $this->assertNull($this->service->computeExamenMark([]));
        $this->assertNull($this->service->computeExamenMark(['lignes' => []]));
        $this->assertNull($this->service->computeExamenMark(['lignes' => array_map(fn() => ['note' => ''], range(1, 7))]));
    }

    public function test_garde_de_nuit_never_produces_a_mark(): void
    {
        $marks = $this->service->computeMarks(
            ['comportement' => array_fill(0, 5, 4), 'aptitudes' => array_fill(0, 10, 4), 'eval_pratique_1' => '20', 'eval_pratique_2' => '20'],
            ['lignes' => [
                ['note' => '1'], ['note' => '2'], ['note' => '2'], ['note' => '10'],
                ['note' => '1'], ['note' => '1'], ['note' => '3'],
            ]]
        );

        $this->assertSame(20.0, $marks['grille_evaluation']);
        $this->assertSame(20.0, $marks['grille_examen']);
        // There is deliberately no garde_de_nuit key: it is administrative only.
        $this->assertArrayNotHasKey('garde_nuit', $marks);
    }

    public function test_non_numeric_entries_are_treated_as_zero_not_errors(): void
    {
        $data = [
            'comportement'    => ['3', 'illisible', 3, '', null],
            'aptitudes'       => ['3', '3', '3', '3', '3', '3', '3', '3', '3', '3'],
            'eval_pratique_1' => 'n/a',
            'eval_pratique_2' => null,
        ];
        // comportement 3+0+3 = 6 ; aptitudes 30 ; pratiques 0
        // (6 + 30 + 0) / 5 = 7.20
        $this->assertSame(7.2, $this->service->computeEvaluationMark($data));
    }
}
