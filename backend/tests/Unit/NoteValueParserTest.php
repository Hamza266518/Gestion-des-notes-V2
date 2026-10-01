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
}
