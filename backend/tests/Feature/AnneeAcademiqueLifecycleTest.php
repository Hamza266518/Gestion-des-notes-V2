<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The lifecycle of an academic year: create, switch, archive, restore, rename.
 *
 * Two behaviours were wrong and are locked here. `current()` interpolated the result
 * of Carbon 3's diffInDays(), which returns a float, so the warning read "dans
 * 4.9999997 jour(s)". And `setCurrent` demoted the outgoing year by setting
 * `is_archived => true` in the same update, so choosing a new current year quietly
 * archived the previous one -- skipping the confirmation that the dedicated archive
 * action asks for. Archiving is now explicit, and `unarchive` plus `update` exist
 * because a mislabelled year used to be permanent: `setCurrent` refuses archived
 * years, so archiving by mistake had no way back.
 *
 * Cleanup deletes by primary key, never by predicate. The suite shares a database
 * and broad deletes here would remove fixture state other tests depend on.
 */
class AnneeAcademiqueLifecycleTest extends TestCase
{
    private User $admin;
    /** @var AnneeAcademique[] */
    private array $created = [];
    private ?int $currentAvantTest = null;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests must claim is_current, so the real holder of the flag is
        // recorded here and put back by primary key in tearDown. Every other
        // feature of the app reads `AnneeAcademique::where('is_current', true)`
        // to decide which year is live -- scan perimeter, scan-data, the student
        // search. Clearing the flag without restoring it leaves the shared test
        // database with no current year and those features silently resolve to
        // "no groups at all".
        $this->currentAvantTest = AnneeAcademique::where('is_current', true)->value('id');

        $this->admin = User::where('email', 'annee.admin@test.local')->first()
            ?? User::create([
                'name'     => 'Annee admin',
                'email'    => 'annee.admin@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
    }

    protected function tearDown(): void
    {
        // Hand is_current back to whichever year held it before this test, then
        // drop what the test added. Restoring by id matters: a blanket
        // `where('is_current', true)->update(false)` followed by
        // `where('is_current', true)->update(true)` restores nothing, because the
        // second statement matches zero rows once the first has run.
        if ($this->currentAvantTest !== null) {
            AnneeAcademique::where('id', $this->currentAvantTest)
                ->update(['is_current' => true]);
        }

        foreach ($this->created as $annee) {
            AnneeAcademique::where('id', $annee->id)->delete();
        }

        parent::tearDown();
    }

    private function makeYear(string $label, array $attrs = []): AnneeAcademique
    {
        $annee = AnneeAcademique::create(array_merge([
            'label'      => $label,
            'is_current' => false,
        ], $attrs));

        $this->created[] = $annee;
        return $annee;
    }

    /**
     * Make a year the only current one.
     *
     * `current()` and `setCurrent()` resolve the live year with
     * `where('is_current', true)->first()`, so a second holder of the flag makes
     * the assertion depend on which row MySQL happens to return first. The
     * outgoing year is demoted here rather than left in place; tearDown hands the
     * flag back by id.
     */
    private function claimCurrent(AnneeAcademique $annee): AnneeAcademique
    {
        if ($this->currentAvantTest !== null && $this->currentAvantTest !== $annee->id) {
            AnneeAcademique::where('id', $this->currentAvantTest)
                ->update(['is_current' => false]);
        }

        $annee->update(['is_current' => true]);
        return $annee;
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    public function test_current_warning_reports_whole_days(): void
    {
        $target = $this->makeYear('TEST JOURS 2090/2091');
        $target->update([
            'start_date' => '2090-09-01',
            // Roughly 10 days out. diffInDays is fractional in Carbon 3, so an
            // uncast interpolation leaks decimals into the message.
            'end_date'   => now()->addDays(10)->toDateString(),
        ]);
        $this->claimCurrent($target);

        $res = $this->asAdmin()->getJson('/api/annee-academique/current');

        $res->assertOk();
        $warning = $res->json('warning');

        $this->assertNotNull($warning, 'A year ending within 30 days must warn');
        $this->assertIsInt(
            $warning['days_left'],
            'days_left must be an int, not Carbon\'s float'
        );
        $this->assertMatchesRegularExpression(
            '/\b\d+ jour\(s\)/',
            $warning['message'],
            'The message must contain a whole number of days'
        );
        $this->assertStringNotContainsString(
            '.',
            $warning['message'],
            'No decimal may leak into the user-facing warning'
        );
    }

    public function test_expired_year_reports_zero_days_not_a_negative_count(): void
    {
        $target = $this->makeYear('TEST EXPIRE 2089/2090');
        $target->update([
            'start_date' => '2089-09-01',
            'end_date'   => now()->subDays(5)->toDateString(),
        ]);
        $this->claimCurrent($target);

        $res = $this->asAdmin()->getJson('/api/annee-academique/current');

        $res->assertOk();
        $warning = $res->json('warning');

        $this->assertNotNull($warning);
        $this->assertSame(0, $warning['days_left'], 'A lapsed year reads as 0, never negative');
        $this->assertTrue($warning['can_archive'], 'A lapsed year should offer archiving');
    }

    public function test_set_current_does_not_archive_the_previous_year(): void
    {
        $previous = $this->claimCurrent($this->makeYear('TEST PREV 2087/2088'));
        $next     = $this->makeYear('TEST NEXT 2088/2089');

        $this->asAdmin()
            ->postJson("/api/admin/annees-academiques/{$next->id}/set-current")
            ->assertOk();

        $previous->refresh();
        $next->refresh();

        $this->assertFalse(
            $previous->is_archived,
            'Switching the current year must not archive the outgoing one as a side effect'
        );
        $this->assertFalse($previous->is_current);
        $this->assertTrue($next->is_current);
    }

    public function test_archive_then_unarchive_round_trips(): void
    {
        $annee = $this->claimCurrent($this->makeYear('TEST ROUNDTRIP 2086/2087'));

        $this->asAdmin()
            ->postJson("/api/admin/annees-academiques/{$annee->id}/archive")
            ->assertOk();

        $this->assertTrue($annee->refresh()->is_archived);
        $this->assertFalse($annee->is_current);

        // The escape hatch that did not exist before: setCurrent refuses archived
        // years, so without unarchive an accidental archive was a one-way door.
        $this->asAdmin()
            ->postJson("/api/admin/annees-academiques/{$annee->id}/unarchive")
            ->assertOk();

        $this->assertFalse(
            $annee->refresh()->is_archived,
            'An archived year must be restorable'
        );
    }

    public function test_unarchiving_a_live_year_is_rejected(): void
    {
        $annee = $this->makeYear('TEST NOTARCH 2085/2086');

        $this->asAdmin()
            ->postJson("/api/admin/annees-academiques/{$annee->id}/unarchive")
            ->assertStatus(422);
    }

    public function test_a_year_label_can_be_corrected(): void
    {
        $annee = $this->makeYear('TEST WRONG 2084/2085');

        $this->asAdmin()
            ->putJson("/api/admin/annees-academiques/{$annee->id}", [
                'label'      => '2083/2084',
                'start_date' => null,
                'end_date'   => null,
            ])
            ->assertOk();

        $this->assertSame('2083/2084', $annee->refresh()->label);
    }

    public function test_update_rejects_a_label_whose_years_do_not_follow(): void
    {
        $annee = $this->makeYear('TEST SEQ 2082/2083');

        $this->asAdmin()
            ->putJson("/api/admin/annees-academiques/{$annee->id}", [
                'label' => 'TEST BAD 2082/2085',
            ])
            ->assertStatus(422);
    }

    public function test_update_rejects_dates_contradicting_the_label(): void
    {
        $annee = $this->makeYear('TEST DATES 2081/2082');

        $this->asAdmin()
            ->putJson("/api/admin/annees-academiques/{$annee->id}", [
                'label'      => 'TEST DATES 2081/2082',
                'start_date' => '2020-09-01',
                'end_date'   => '2021-07-01',
            ])
            ->assertStatus(422);

        $this->assertSame(
            'TEST DATES 2081/2082',
            $annee->refresh()->label,
            'A rejected update must leave the row untouched'
        );
    }

    public function test_missing_current_year_fails_closed_on_the_student_list(): void
    {
        // The window between archiving a year and switching to the next one has
        // no row holding is_current. The list must resolve to nothing rather
        // than falling back to every year's students.
        AnneeAcademique::where('is_current', true)->update(['is_current' => false]);

        $res = $this->asAdmin()->getJson('/api/admin/etudiants');

        $res->assertOk();
        $this->assertSame(
            [],
            $res->json('data'),
            'No current year must yield an empty list, never all years'
        );
    }
}