<?php

namespace Tests\Unit;

use App\Services\NoteParserService;
use Tests\TestCase;

class NoteValueParserTest extends TestCase
{
    protected NoteParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new NoteParserService();
    }

    public function test_absence_tokens_become_zero_with_the_absence_flag(): void
    {
        foreach (['AB', 'ab', 'ABS', 'Absent', 'absente', 'A', 'AJ', 'NN', 'Non', 'justifie', 'N A', 'X'] as $raw) {
            $result = $this->parser->parseValue($raw);
            $this->assertTrue($result['est_absent'], "'{$raw}' should be an absence");
            $this->assertFalse($result['invalid'], "'{$raw}' should be a valid absence");
            $this->assertSame(0.0, $result['valeur'], "'{$raw}' should store 0");
        }
    }

    public function test_slash_absence_token_is_recognised(): void
    {
        $result = $this->parser->parseValue('N/A');
        $this->assertTrue($result['est_absent']);
        $this->assertFalse($result['invalid']);
        $this->assertSame(0.0, $result['valeur']);
    }

    public function test_absence_marker_next_to_a_number_wins(): void
    {
        foreach (['15.00 (AB)', 'AB 12,5', '12.5 absent', 'non justifié'] as $raw) {
            $result = $this->parser->parseValue($raw);
            $this->assertTrue($result['est_absent'], "'{$raw}' should be an absence");
            $this->assertSame(0.0, $result['valeur']);
        }
    }

    public function test_real_grades_are_parsed(): void
    {
        $this->assertSame(12.5, $this->parser->parseValue('12,5')['valeur']);
        $this->assertSame(12.5, $this->parser->parseValue('12.5')['valeur']);
        $this->assertSame(14.0, $this->parser->parseValue('14')['valeur']);
        $this->assertSame(14.0, $this->parser->parseValue(' 14 ')['valeur']);
        $this->assertSame(16.5, $this->parser->parseValue(' 16,50 ')['valeur']);
    }

    public function test_a_bare_zero_is_a_real_grade_not_an_absence(): void
    {
        $result = $this->parser->parseValue('0');
        $this->assertFalse($result['est_absent']);
        $this->assertFalse($result['invalid']);
        $this->assertSame(0.0, $result['valeur']);
    }

    public function test_fraction_notation_uses_its_own_denominator(): void
    {
        $result = $this->parser->parseValue('14/20');
        $this->assertSame(14.0, $result['valeur']);
        $this->assertFalse($result['est_absent']);

        $onTen = $this->parser->parseValue('8/10');
        $this->assertSame(8.0, $onTen['valeur']);
    }

    /**
     * Regression: the dot was stripped as sentence punctuation before the fraction
     * regex ran, so "14.5/20" became "145/20" and was clamped to a perfect 20/20.
     */
    public function test_dot_decimal_fractions_are_not_clamped_up_to_full_marks(): void
    {
        foreach ([
            '14.5/20' => 14.5,
            '14.5 sur 20' => 14.5,
            '8.5/10' => 8.5,
            '9.75 sur 10' => 9.75,
            '12.25/20' => 12.25,
            '0.5/20' => 0.5,
        ] as $raw => $expected) {
            $result = $this->parser->parseValue($raw);
            $this->assertFalse($result['invalid'], "'{$raw}' should be readable");
            $this->assertFalse($result['est_absent'], "'{$raw}' is a grade, not an absence");
            $this->assertSame($expected, $result['valeur'], "'{$raw}' should parse as {$expected}");
        }
    }

    /**
     * A numerator above the denominator is a misread, never a perfect score.
     */
    public function test_incoherent_fraction_needs_a_human_instead_of_awarding_full_marks(): void
    {
        foreach (['145/20', '150/20', '14/2', '12.5/1'] as $raw) {
            $result = $this->parser->parseValue($raw);
            $this->assertTrue($result['invalid'], "'{$raw}' should need a human");
            $this->assertNull($result['valeur'], "'{$raw}' must not be stored as a real grade");
            $this->assertFalse($result['est_absent']);
        }
    }

    public function test_values_are_clamped_to_the_note_maximum(): void
    {
        $this->assertSame(20.0, $this->parser->parseValue('25')['valeur']);
        $this->assertSame(20.0, $this->parser->parseValue('25', 20.0)['valeur']);
        $this->assertSame(10.0, $this->parser->parseValue('12', 10.0)['valeur']);
        $this->assertSame(0.0, $this->parser->parseValue('-3')['valeur']);
    }

    public function test_custom_note_maximum_is_respected(): void
    {
        $result = $this->parser->parseValue('7', 10.0);
        $this->assertSame(7.0, $result['valeur']);
    }

    public function test_blank_cells_are_invalid_rather_than_silently_zero(): void
    {
        foreach (['', '  ', '-', '--', '...', '/', 'NC'] as $raw) {
            $result = $this->parser->parseValue($raw);
            $this->assertTrue($result['invalid'], "'{$raw}' should need a human");
            $this->assertNull($result['valeur'], "'{$raw}' must not become 0");
            $this->assertFalse($result['est_absent']);
        }
    }

    public function test_null_is_invalid(): void
    {
        $result = $this->parser->parseValue(null);
        $this->assertTrue($result['invalid']);
        $this->assertNull($result['valeur']);
    }

    public function test_unreadable_text_is_invalid_not_guessed(): void
    {
        $result = $this->parser->parseValue('illisible');
        $this->assertTrue($result['invalid']);
        $this->assertNull($result['valeur']);
        $this->assertFalse($result['est_absent']);
    }

    public function test_numeric_input_is_passed_through(): void
    {
        $this->assertSame(15.0, $this->parser->parseValue(15)['valeur']);
        $this->assertSame(15.5, $this->parser->parseValue(15.5)['valeur']);
    }

    public function test_only_quarter_step_grades_are_accepted(): void
    {
        // Accept valid quarter steps
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0, 1.25, 14.5, 14.25, 14.75, 20.0] as $v) {
            $res = $this->parser->parseValue($v);
            $this->assertFalse($res['invalid'], $v . ' should be valid');
        }
        // Reject non-quarter steps
        foreach ([0.1, 0.2, 0.3, 0.4, 0.6, 0.7, 0.8, 0.9, 14.7, 14.9, 15.1, 15.3] as $v) {
            $res = $this->parser->parseValue($v);
            $this->assertTrue($res['invalid'], $v . ' should be invalid');
        }
        // String forms
        $this->assertFalse($this->parser->parseValue('14.25')['invalid']);
        $this->assertFalse($this->parser->parseValue('14,25')['invalid']);
        $this->assertFalse($this->parser->parseValue('14.50')['invalid']);
        $this->assertFalse($this->parser->parseValue('14.75')['invalid']);
        $this->assertTrue($this->parser->parseValue('14.7')['invalid']);
        $this->assertTrue($this->parser->parseValue('14,7')['invalid']);
        $this->assertTrue($this->parser->parseValue('14.9')['invalid']);
    }
}
