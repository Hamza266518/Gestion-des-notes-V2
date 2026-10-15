<?php

namespace Tests\Feature;

use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Unite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration 2026_10_10_000001 turned the Stage units' controls into sequence-free
 * direct controls (one per filière/année). Its rollback is the one operation
 * that can destroy marks written by StageService: it must refuse once a Stage
 * grade exists, and only succeed on an untouched structure.
 */
class StageMigrationRollbackTest extends TestCase
{
    private $migration;
    private array $created = ['unites' => [], 'controles' => []];
    private array $parkedNotes = [];
    private ?Etudiant $etudiant = null;

    protected function setUp(): void
    {
        parent::setUp();

        $file = glob(base_path('database/migrations/2026_10_10_000001_*.php'))[0] ?? null;
        $this->assertNotNull($file, 'Migration 000001 (stage, sequence-free) file must exist.');
        $this->migration = require $file;

        $this->etudiant = Etudiant::whereNotNull('groupe_id')->whereHas('groupe.niveau')->first();
        $this->assertNotNull($this->etudiant);
    }

    protected function tearDown(): void
    {
        // down() reverses the whole Stage structure (every unit named 'Stage'),
        // so re-apply it before cleaning up. Without this the fixture DB would be
        // left without the official Stage structure.
        try {
            $this->migration->up();
        } catch (\Throwable $e) {
            // Nothing to restore if the file could not be loaded.
        }

        $this->restoreParkedNotes();

        Note::whereIn('controle_id', $this->created['controles'])->delete();
        Controle::whereIn('id', $this->created['controles'])->delete();
        DB::table('curriculum_corrections')
            ->where('cible_type', 'unites')
            ->whereIn('cible_id', $this->created['unites'])
            ->delete();
        Unite::whereIn('id', $this->created['unites'])->delete();
        parent::tearDown();
    }

    /**
     * down() only touches units literally named 'Stage', so the fixture must be
     * one of those. Using a real Stage unit would delete the shipped structure,
     * hence the dedicated unit below with the sequence-free direct controls the
     * migration produces.
     */
    private function makeStageUnit(): Unite
    {
        $unite = Unite::create([
            'filiere_id'       => $this->etudiant->groupe->niveau->filiere_id,
            'nom'              => 'Stage',
            'coefficient'      => 4,
            'numero_annee'     => 99,
            'semestre'         => 2,
            'ordre'            => 997,
            'is_active'        => true,
        ]);
        $this->created['unites'][] = $unite->id;

        foreach ([1, 2] as $numero) {
            $this->created['controles'][] = Controle::create([
                'unite_id'   => $unite->id,
                'numero'     => $numero,
                'nom'        => 'Contrôle ' . $numero,
                'note_max'   => 20,
            ])->id;
        }

        return $unite;
    }

    /**
     * Withdraw every mark written on the shipped Stage units (the two official
     * grid grades) so down() sees an untouched structure. Their rows are kept in
     * $parkedNotes and re-inserted by restoreParkedNotes().
     */
    private function parkStageNotes(): void
    {
        $rows = Note::whereHas('controle', function ($query) {
            $query->whereNull('sequence_id')->whereHas('unite', function ($unite) {
                $unite->where('nom', 'Stage');
            });
        })->get();

        foreach ($rows as $note) {
            $this->parkedNotes[] = [
                'etudiant_id'  => $note->etudiant_id,
                'controle_id'  => $note->controle_id,
                'valeur'       => $note->valeur,
                'est_absent'   => $note->est_absent,
                'is_confirmed' => $note->is_confirmed,
                'confirmed_at' => $note->confirmed_at,
            ];
            $note->delete();
        }
    }

    private function restoreParkedNotes(): void
    {
        foreach ($this->parkedNotes as $parked) {
            Note::firstOrCreate(['controle_id' => $parked['controle_id'], 'etudiant_id' => $parked['etudiant_id']], $parked);
        }
        $this->parkedNotes = [];
    }

    public function test_rollback_refuses_and_keeps_the_grades(): void
    {
        $unite = $this->makeStageUnit();
        $controleId = $this->created['controles'][0];

        Note::create([
            'etudiant_id'  => $this->etudiant->id,
            'controle_id'  => $controleId,
            'valeur'       => 14.5,
            'est_absent'   => false,
            'is_confirmed' => true,
        ]);

        $threw = false;
        try {
            $this->migration->down();
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Rollback must be refused once a Stage grade exists.');
        $this->assertSame(14.5, (float) Note::where('controle_id', $controleId)->value('valeur'));
        $this->assertTrue(Controle::where('id', $controleId)->exists());
        $this->assertSame(
            $unite->id,
            (int) Controle::where('id', $controleId)->value('unite_id'),
            'A refused rollback must leave the controls on the Stage unit.'
        );
    }

    public function test_rollback_succeeds_when_no_grade_was_ever_written(): void
    {
        // The official Stage grid marks live on the shipped Stage units, so the
        // guard would refuse down() by design. Park those marks for the duration
        // of this test so the rollback path can be exercised on a clean corpus,
        // then put them back (tearDown) exactly as they were.
        $this->parkStageNotes();

        $unite = $this->makeStageUnit();

        $this->migration->down();

        $this->assertTrue(
            \App\Models\Sequence::where('unite_id', $unite->id)->exists(),
            'Rollback must restore the unit\'s sequence.'
        );
        $this->assertSame(
            2,
            (int) \App\Models\Sequence::where('unite_id', $unite->id)->value('nombre_controles')
        );

        // up() restores everything, so the shipped Stage units come back too.
        $this->migration->up();

        $this->assertFalse(
            \App\Models\Sequence::where('unite_id', $unite->id)->exists(),
            'Re-applying must make the Stage controls sequence-free again.'
        );
        $this->assertSame(
            2,
            (int) Controle::where('unite_id', $unite->id)->whereNull('sequence_id')->count()
        );
    }
}