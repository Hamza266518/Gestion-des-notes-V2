<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\StageEvaluation;
use App\Models\Unite;
use App\Services\StageService;
use Tests\TestCase;

/**
 * The Stage page stores JSON grids; the averages read `notes`. This covers the
 * bridge between the two, plus the guards that protect real grades.
 */
class StageSyncTest extends TestCase
{
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private StageService $service;
    private array $touched = ['unites' => [], 'sequences' => [], 'controles' => []];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StageService::class);

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
        // Notes first (FK), then controls, sequences, and any unit we created.
        Note::whereIn('controle_id', $this->touched['controles'])->delete();
        Controle::whereIn('id', $this->touched['controles'])->delete();
        Sequence::whereIn('id', $this->touched['sequences'])->delete();
        Unite::whereIn('id', $this->touched['unites'])->delete();

        StageEvaluation::where('etudiant_id', $this->etudiant->id)
            ->where('annee_academique_id', $this->annee->id)
            ->whereIn('type', ['garde_nuit', 'grille_evaluation', 'grille_examen'])
            ->delete();

        parent::tearDown();
    }

    /**
     * StageService resolves its target by unit name 'Stage', so the fixture must
     * provide one for this student's filière/année. The migration already
     * created the real ones; this only fills a gap.
     */
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
                'filiere_id'       => $filiereId,
                'nom'              => 'Stage',
                'coefficient'      => 4,
                'nombre_controles' => 2,
                'numero_annee'     => $anneeNum,
                'semestre'         => 2,
                'ordre'            => 999,
                'is_active'        => true,
            ]);
            $this->touched['unites'][] = $unite->id;
        }

        $sequence = Sequence::where('unite_id', $unite->id)->first();
        if (!$sequence) {
            $sequence = Sequence::create([
                'unite_id'         => $unite->id,
                'nom'              => 'Stage',
                'coefficient'      => 2,
                'nombre_controles' => 2,
            ]);
            $this->touched['sequences'][] = $sequence->id;
        }

        foreach ([['cc', 1], ['pratique', 2]] as [$type, $numero]) {
            $controle = Controle::where('sequence_id', $sequence->id)
                ->where('type', $type)
                ->first();
            if (!$controle) {
                $controle = Controle::create([
                    'sequence_id' => $sequence->id,
                    'numero'      => $numero,
                    'type'        => $type,
                    'note_max'    => 20,
                ]);
                $this->touched['controles'][] = $controle->id;
            } else {
                // Track pre-existing controls too: we write notes against them.
                $this->touched['controles'][] = $controle->id;
            }
        }
    }

    private function storeGrid(string $type, array $data): void
    {
        StageEvaluation::updateOrCreate(
            [
                'etudiant_id'        => $this->etudiant->id,
                'annee_academique_id' => $this->annee->id,
                'type'               => $type,
            ],
            ['data' => $data]
        );
    }

    private function noteValueFor(string $type): ?float
    {
        $controleId = $this->controleIdFor($type);
        if (!$controleId) return null;

        return Note::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $controleId)
            ->value('valeur');
    }

    /** Control of the given type on THIS student's Stage unit, nothing else. */
    private function controleIdFor(string $type): ?int
    {
        return Controle::where('type', $type)
            ->whereIn('sequence_id', Sequence::whereIn('unite_id', Unite::where('nom', 'Stage')
                ->where('filiere_id', $this->etudiant->groupe->niveau->filiere_id)
                ->where('numero_annee', $this->etudiant->groupe->niveau->numero)
                ->where('is_active', true)
                ->pluck('id'))
                ->pluck('id'))
            ->value('id');
    }

    public function test_saving_the_grids_writes_confirmed_notes(): void
    {
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 3),
            'aptitudes'       => array_fill(0, 10, 3),
            'eval_pratique_1' => '10',
            'eval_pratique_2' => '8',
        ]);
        $this->storeGrid('grille_examen', ['lignes' => [
            ['note' => '1'], ['note' => '2'], ['note' => '2'], ['note' => '8'],
            ['note' => '1'], ['note' => '1'], ['note' => '3'],
        ]]);

        $result = $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertSame(12.6, (float) $this->noteValueFor('cc'));
        $this->assertSame(18.0, (float) $this->noteValueFor('pratique'));

        $controleId = $this->controleIdFor('cc');
        $note = Note::where('etudiant_id', $this->etudiant->id)->where('controle_id', $controleId)->first();
        $this->assertTrue((bool) $note->is_confirmed, 'A derived mark must be protected as confirmed.');
        $this->assertFalse((bool) $note->est_absent);

        $this->assertArrayHasKey('synced', $result);
    }

    public function test_garde_de_nuit_alone_writes_nothing(): void
    {
        $this->storeGrid('garde_nuit', ['nuit' => 'PRESENTE']);

        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNull($this->noteValueFor('cc'));
        $this->assertNull($this->noteValueFor('pratique'));
    }

    public function test_a_real_zero_is_written_not_treated_as_empty(): void
    {
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 0),
            'aptitudes'       => array_fill(0, 10, 0),
            'eval_pratique_1' => '0',
            'eval_pratique_2' => '0',
        ]);

        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertSame(0.0, (float) $this->noteValueFor('cc'));
    }

    public function test_resaving_updates_in_place_instead_of_duplicating(): void
    {
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 2),
            'aptitudes'       => array_fill(0, 10, 2),
            'eval_pratique_1' => '10',
            'eval_pratique_2' => '10',
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $first = $this->noteValueFor('cc');

        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 4),
            'aptitudes'       => array_fill(0, 10, 4),
            'eval_pratique_1' => '20',
            'eval_pratique_2' => '20',
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNotSame($first, $this->noteValueFor('cc'));
        $this->assertSame(20.0, (float) $this->noteValueFor('cc'));

        $controleId = $this->controleIdFor('cc');
        $this->assertSame(1, Note::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $controleId)->count());
    }

    public function test_clearing_a_grid_removes_the_derived_note(): void
    {
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 3),
            'aptitudes'       => array_fill(0, 10, 3),
            'eval_pratique_1' => '10',
            'eval_pratique_2' => '8',
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $this->assertSame(12.6, (float) $this->noteValueFor('cc'));

        // Blank the grid the way an emptied form posts it.
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, null),
            'aptitudes'       => array_fill(0, 10, ''),
            'eval_pratique_1' => '',
            'eval_pratique_2' => null,
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNull($this->noteValueFor('cc'), 'A cleared grid must not leave a stale mark behind.');
    }

    public function test_archived_year_is_never_rewritten(): void
    {
        $this->storeGrid('grille_evaluation', [
            'comportement'    => array_fill(0, 5, 4),
            'aptitudes'       => array_fill(0, 10, 4),
            'eval_pratique_1' => '20',
            'eval_pratique_2' => '20',
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $this->assertSame(20.0, (float) $this->noteValueFor('cc'));

        $original = $this->annee->is_archived;
        $this->annee->update(['is_archived' => true]);

        try {
            // A second, different score must be refused on an archived year.
            $this->storeGrid('grille_evaluation', [
                'comportement'    => array_fill(0, 5, 1),
                'aptitudes'       => array_fill(0, 10, 1),
                'eval_pratique_1' => '2',
                'eval_pratique_2' => '2',
            ]);
            $result = $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

            $this->assertSame(20.0, (float) $this->noteValueFor('cc'));
            $this->assertNotSame('', $result['message']);
        } finally {
            $this->annee->update(['is_archived' => $original]);
        }
    }
}