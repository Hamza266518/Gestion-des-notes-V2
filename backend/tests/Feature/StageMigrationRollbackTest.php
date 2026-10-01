<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration 2026_09_29_000007 created the Stage controls, so its rollback is the
 * one operation that can destroy marks written by StageService. It must refuse
 * instead of deleting, and only succeed on an untouched structure.
 */
class StageMigrationRollbackTest extends TestCase
{
    private $migration;
    private array $created = ['unites' => [], 'sequences' => [], 'controles' => []];
    private ?Etudiant $etudiant = null;

    protected function setUp(): void
    {
        parent::setUp();

        $file = glob(base_path('database/migrations/2026_09_29_000007_*.php'))[0] ?? null;
        $this->assertNotNull($file, 'Migration 000007 file must exist.');
        $this->migration = require $file;

        $this->etudiant = Etudiant::whereNotNull('groupe_id')->whereHas('groupe.niveau')->first();
        $this->assertNotNull($this->etudiant);
    }

    protected function tearDown(): void
    {
        // down() removes every unit named 'Stage', including the shipped ones, so
        // re-apply it before cleaning up. Without this the fixture DB would be
        // left without the official Stage structure.
        try {
            $this->migration->up();
        } catch (\Throwable $e) {
            // Nothing to restore if the file could not be loaded.
        }

        Note::whereIn('controle_id', $this->created['controles'])->delete();
        Controle::whereIn('id', $this->created['controles'])->delete();
        Sequence::whereIn('id', $this->created['sequences'])->delete();
        Unite::whereIn('id', $this->created['unites'])->delete();
        parent::tearDown();
    }

    /**
     * down() only touches units literally named 'Stage', so the fixture must be
     * one of those. Using a real Stage unit would delete the shipped structure,
     * hence the dedicated unit below.
     */
    private function makeStageUnit(): Unite
    {
        $unite = Unite::create([
            'filiere_id'       => $this->etudiant->groupe->niveau->filiere_id,
            'nom'              => 'Stage',
            'coefficient'      => 4,
            'nombre_controles' => 2,
            'numero_annee'     => 99,
            'semestre'         => 2,
            'ordre'            => 997,
            'is_active'        => true,
        ]);
        $this->created['unites'][] = $unite->id;

        $sequence = Sequence::create([
            'unite_id'         => $unite->id,
            'nom'              => 'Stage',
            'coefficient'      => 2,
            'nombre_controles' => 2,
        ]);
        $this->created['sequences'][] = $sequence->id;

        foreach ([['cc', 1], ['pratique', 2]] as [$type, $numero]) {
            $this->created['controles'][] = Controle::create([
                'sequence_id' => $sequence->id,
                'numero'      => $numero,
                'type'        => $type,
                'note_max'    => 20,
            ])->id;
        }

        return $unite;
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
        $this->assertTrue(Sequence::where('unite_id', $unite->id)->exists());
    }

    public function test_rollback_succeeds_when_no_grade_was_ever_written(): void
    {
        $unite = $this->makeStageUnit();
        $controleId = $this->created['controles'][0];

        $this->migration->down();

        $this->assertFalse(Controle::where('id', $controleId)->exists());
        $this->assertFalse(Sequence::where('unite_id', $unite->id)->exists());
        $this->assertTrue(Unite::where('id', $unite->id)->exists(), 'The unit itself must survive.');

        // up() restores everything, so the shipped Stage units come back too.
        $this->migration->up();

        $this->assertTrue(
            Sequence::whereIn('unite_id', Unite::where('nom', 'Stage')->pluck('id'))->exists(),
            'Re-applying must give every Stage unit its sequence back.'
        );
    }
}