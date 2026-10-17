<?php

namespace Tests\Unit;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Services\PublicationLockService;
use Tests\TestCase;

/**
 * The type mapping is the whole of the semester rule, and it needs no fixtures.
 *
 * Kept as a unit test because the feature database only carries semester 2
 * controls, so asserting the mapping through the database would leave the S1
 * half of the rule untested.
 */
class PublicationLockSemestreTest extends TestCase
{
    public function test_semester_one_is_closed_by_s1_and_by_a_bulletin(): void
    {
        $types = app(PublicationLockService::class)->typesPour(1);

        $this->assertContains('notes_s1', $types);
        $this->assertContains('bulletin', $types);
        $this->assertNotContains(
            'notes_s2',
            $types,
            'An S2 publication must not close semester 1.'
        );
    }

    public function test_semester_two_is_closed_by_s2_and_by_a_bulletin(): void
    {
        $types = app(PublicationLockService::class)->typesPour(2);

        $this->assertContains('notes_s2', $types);
        $this->assertContains('bulletin', $types);
        $this->assertNotContains(
            'notes_s1',
            $types,
            'An S1 publication must not close semester 2.'
        );
    }

    public function test_a_bulletin_closes_both_semesters(): void
    {
        $locks = app(PublicationLockService::class);

        $this->assertContains('bulletin', $locks->typesPour(1));
        $this->assertContains('bulletin', $locks->typesPour(2));
    }

    /**
     * Every lock is scoped to the current year, so a missing current year means
     * the scope cannot be resolved. Answering "unlocked" there reopened every
     * published cohort for the window between archiving a year and switching to
     * the next one. The guard must fail closed instead.
     */
    public function test_lock_fails_closed_when_no_year_is_current(): void
    {
        $before = AnneeAcademique::where('is_current', true)->value('id');
        AnneeAcademique::where('is_current', true)->update(['is_current' => false]);

        try {
            $locks = app(PublicationLockService::class);
            $controle = new Controle();

            $this->assertTrue(
                $locks->verrouilleGroupe($controle, 123),
                'With no current year a group must read as locked, never unlocked'
            );
            $this->assertTrue(
                $locks->verrouilleTout($controle),
                'With no current year every group must read as locked'
            );
        } finally {
            if ($before !== null) {
                AnneeAcademique::where('id', $before)->update(['is_current' => true]);
            }
        }
    }
}
