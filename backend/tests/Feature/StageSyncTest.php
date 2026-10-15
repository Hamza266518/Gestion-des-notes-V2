<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\StageEvaluation;
use App\Models\StageNote;
use App\Models\Unite;
use App\Services\StageService;
use Tests\TestCase;

/**
 * The Stage page stores one JSON grid per service; the averages read `notes`
 * (Évaluation) plus `stage_notes` (Examen). This covers the bridge between the
 * two, plus the guards that protect real grades.
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
        // Marks the student may have derived on this year's Stage units, whether
        // on shipped controls or on ones this test created.
        $stageUniteIds = Unite::where('nom', 'Stage')
            ->where('filiere_id', $this->etudiant->groupe->niveau->filiere_id)
            ->where('numero_annee', $this->etudiant->groupe->niveau->numero)
            ->pluck('id');

        $stageControleIds = $stageUniteIds->isNotEmpty()
            ? Controle::whereIn('unite_id', $stageUniteIds)->pluck('id')
            : collect();

        if ($stageControleIds->isNotEmpty()) {
            Note::where('etudiant_id', $this->etudiant->id)
                ->whereIn('controle_id', $stageControleIds)
                ->delete();
            StageNote::where('etudiant_id', $this->etudiant->id)
                ->whereIn('controle_id', $stageControleIds)
                ->delete();
        }

        // Only the controls this test created; shipped controls must survive.
        Controle::whereIn('id', $this->touched['controles'])->delete();
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
     * created the real ones (as sequence-free direct controls); this only fills
     * a gap.
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
                'numero_annee'     => $anneeNum,
                'semestre'         => 2,
                'ordre'            => 999,
                'is_active'        => true,
            ]);
            $this->touched['unites'][] = $unite->id;
        }

        foreach ([1, 2] as $numero) {
            $controle = Controle::where('unite_id', $unite->id)
                ->where('numero', $numero)
                ->first();
            if (!$controle) {
                $controle = Controle::create([
                    'unite_id'   => $unite->id,
                    'numero'     => $numero,
                    'nom'        => 'Contrôle ' . $numero,
                    'note_max'   => 20,
                ]);
                $this->touched['controles'][] = $controle->id;
            }
        }
    }

    /** Controls of THIS student's Stage unit, nothing else. */
    private function stageControles()
    {
        return Controle::whereIn('unite_id', Unite::where('nom', 'Stage')
            ->where('filiere_id', $this->etudiant->groupe->niveau->filiere_id)
            ->where('numero_annee', $this->etudiant->groupe->niveau->numero)
            ->where('is_active', true)
            ->pluck('id'));
    }

    private function controleIdFor(int $numero): ?int
    {
        return $this->stageControles()->where('numero', $numero)->value('id');
    }

    private function serviceNomFor(int $numero): string
    {
        return (string) $this->stageControles()->where('numero', $numero)->value('nom');
    }

    private function storeGrid(string $type, array $map): void
    {
        StageEvaluation::updateOrCreate(
            [
                'etudiant_id'        => $this->etudiant->id,
                'annee_academique_id' => $this->annee->id,
                'type'               => $type,
            ],
            ['data' => $map]
        );
    }

    /** The Évaluation mark of the service numbered $numero, in `notes`. */
    private function evaluationFor(int $numero): ?float
    {
        $controleId = $this->controleIdFor($numero);
        if (!$controleId) return null;

        return Note::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $controleId)
            ->value('valeur');
    }

    /** The Examen mark of the service numbered $numero, in `stage_notes`. */
    private function examenFor(int $numero): ?float
    {
        $controleId = $this->controleIdFor($numero);
        if (!$controleId) return null;

        return StageNote::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $controleId)
            ->where('type', StageNote::EXAMEN_TYPE)
            ->value('valeur');
    }

    public function test_saving_the_grids_writes_an_evaluation_note_and_an_exam_mark(): void
    {
        $service1 = $this->serviceNomFor(1);
        $service2 = $this->serviceNomFor(2);

        $this->storeGrid('grille_evaluation', [
            $service1 => [
                'comportement'    => array_fill(0, 5, 3),
                'aptitudes'       => array_fill(0, 10, 3),
                'eval_pratique_1' => '10',
                'eval_pratique_2' => '8',
            ],
        ]);
        $this->storeGrid('grille_examen', [
            $service2 => ['lignes' => [
                ['note' => '1'], ['note' => '2'], ['note' => '2'], ['note' => '8'],
                ['note' => '1'], ['note' => '1'], ['note' => '3'],
            ]],
        ]);

        $result = $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertSame(12.6, (float) $this->evaluationFor(1));
        $this->assertSame(18.0, (float) $this->examenFor(2));

        $controleId = $this->controleIdFor(1);
        $note = Note::where('etudiant_id', $this->etudiant->id)->where('controle_id', $controleId)->first();
        $this->assertTrue((bool) $note->is_confirmed, 'A derived mark must be protected as confirmed.');
        $this->assertFalse((bool) $note->est_absent);

        $this->assertArrayHasKey('synced', $result);
    }

    public function test_garde_de_nuit_alone_writes_nothing(): void
    {
        $this->storeGrid('garde_nuit', ['nuit' => 'PRESENTE']);

        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNull($this->evaluationFor(1));
        $this->assertNull($this->examenFor(2));
    }

    public function test_a_real_zero_is_written_not_treated_as_empty(): void
    {
        $this->storeGrid('grille_evaluation', [
            $this->serviceNomFor(1) => [
                'comportement'    => array_fill(0, 5, 0),
                'aptitudes'       => array_fill(0, 10, 0),
                'eval_pratique_1' => '0',
                'eval_pratique_2' => '0',
            ],
        ]);

        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertSame(0.0, (float) $this->evaluationFor(1));
    }

    public function test_resaving_updates_in_place_instead_of_duplicating(): void
    {
        $service1 = $this->serviceNomFor(1);

        $this->storeGrid('grille_evaluation', [
            $service1 => [
                'comportement'    => array_fill(0, 5, 2),
                'aptitudes'       => array_fill(0, 10, 2),
                'eval_pratique_1' => '10',
                'eval_pratique_2' => '10',
            ],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $first = $this->evaluationFor(1);

        $this->storeGrid('grille_evaluation', [
            $service1 => [
                'comportement'    => array_fill(0, 5, 4),
                'aptitudes'       => array_fill(0, 10, 4),
                'eval_pratique_1' => '20',
                'eval_pratique_2' => '20',
            ],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNotSame($first, $this->evaluationFor(1));
        $this->assertSame(20.0, (float) $this->evaluationFor(1));

        $controleId = $this->controleIdFor(1);
        $this->assertSame(1, Note::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $controleId)->count());
    }

    public function test_clearing_a_grid_removes_the_derived_note(): void
    {
        $service1 = $this->serviceNomFor(1);

        $this->storeGrid('grille_evaluation', [
            $service1 => [
                'comportement'    => array_fill(0, 5, 3),
                'aptitudes'       => array_fill(0, 10, 3),
                'eval_pratique_1' => '10',
                'eval_pratique_2' => '8',
            ],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $this->assertSame(12.6, (float) $this->evaluationFor(1));

        // Blank the grid the way an emptied form posts it.
        $this->storeGrid('grille_evaluation', [
            $service1 => [
                'comportement'    => array_fill(0, 5, null),
                'aptitudes'       => array_fill(0, 10, ''),
                'eval_pratique_1' => '',
                'eval_pratique_2' => null,
            ],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNull($this->evaluationFor(1), 'A cleared grid must not leave a stale mark behind.');
    }

    public function test_clearing_the_exam_grid_removes_the_exam_mark(): void
    {
        $service2 = $this->serviceNomFor(2);

        $this->storeGrid('grille_examen', [
            $service2 => ['lignes' => array_map(fn () => ['note' => '1'], range(1, 7))],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $this->assertSame(7.0, (float) $this->examenFor(2));

        // Drop the service from the map entirely: its exam mark must go too.
        $this->storeGrid('grille_examen', []);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

        $this->assertNull($this->examenFor(2), 'A removed exam grid must not leave a stale mark behind.');
    }

    public function test_archived_year_is_never_rewritten(): void
    {
        $this->storeGrid('grille_evaluation', [
            $this->serviceNomFor(1) => [
                'comportement'    => array_fill(0, 5, 4),
                'aptitudes'       => array_fill(0, 10, 4),
                'eval_pratique_1' => '20',
                'eval_pratique_2' => '20',
            ],
        ]);
        $this->service->syncForEtudiant($this->etudiant, $this->annee->id);
        $this->assertSame(20.0, (float) $this->evaluationFor(1));

        $original = $this->annee->is_archived;
        $this->annee->update(['is_archived' => true]);

        try {
            // A second, different score must be refused on an archived year.
            $this->storeGrid('grille_evaluation', [
                $this->serviceNomFor(1) => [
                    'comportement'    => array_fill(0, 5, 1),
                    'aptitudes'       => array_fill(0, 10, 1),
                    'eval_pratique_1' => '2',
                    'eval_pratique_2' => '2',
                ],
            ]);
            $result = $this->service->syncForEtudiant($this->etudiant, $this->annee->id);

            $this->assertSame(20.0, (float) $this->evaluationFor(1));
            $this->assertNotSame('', $result['message']);
        } finally {
            $this->annee->update(['is_archived' => $original]);
        }
    }
}