<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Services\BulletinService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulletinCalculationTest extends TestCase
{
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    private Sequence $sequence;
    private int $ccId;
    private int $theoId;
    private int $praId;

    /**
     * Build one fully-marked unit on top of the seeded hierarchy:
     *   CC = 12, Theorique = 16, Pratique = 8
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->etudiant = Etudiant::where('annee_academique_id', $this->annee->id)
            ->whereNotNull('groupe_id')
            ->whereHas('groupe.niveau')
            ->first();

        $this->assertNotNull($this->etudiant, 'Seeded data must provide an etudiant with a groupe/niveau');

        $filiereId = $this->etudiant->groupe->niveau->filiere_id;
        $niveauNumero = $this->etudiant->groupe->niveau->numero;

        $this->unite = Unite::create([
            'filiere_id'      => $filiereId,
            'nom'             => 'UNITE CALCUL TEST',
            'coefficient'     => 2,
            'nombre_controles'=> 3,
            'numero_annee'    => $niveauNumero,
            'semestre'        => 1,
            'ordre'           => 999,
            'is_active'       => true,
        ]);

        $this->sequence = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => 'SEQ CALCUL TEST',
            'coefficient'      => 1,
            'nombre_controles' => 3,
        ]);

        $this->ccId   = $this->makeControle('cc', 'CC TEST');
        $this->theoId = $this->makeControle('theorique', 'THEO TEST');
        $this->praId  = $this->makeControle('pratique', 'PRA TEST');

        $this->mark(12, $this->ccId);
        $this->mark(16, $this->theoId);
        $this->mark(8,  $this->praId);
    }

    protected function tearDown(): void
    {
        if (isset($this->etudiant)) {
            $ids = Note::where('etudiant_id', $this->etudiant->id)
                ->whereIn('controle_id', array_filter([$this->ccId ?? null, $this->theoId ?? null, $this->praId ?? null]))
                ->pluck('controle_id');
            if ($ids->isNotEmpty()) {
                Controle::whereIn('id', $ids)->delete();
            }
        }
        if (isset($this->sequence)) {
            $this->sequence->delete();
        }
        if (isset($this->unite)) {
            $this->unite->delete();
        }

        parent::tearDown();
    }

    private function makeControle(string $type, string $nom): int
    {
        return Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => 1,
            'type'        => $type,
            'note_max'    => 20,
        ])->id;
    }

    private function mark(float $valeur, int $controleId): void
    {
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $controleId,
            'valeur'      => $valeur,
        ]);
    }

    private function bulletin(): array
    {
        return app(BulletinService::class)->calculateBulletin(
            $this->etudiant->id,
            $this->annee->id,
            1
        );
    }

    public function test_per_unit_average_is_computed(): void
    {
        $b = $this->bulletin();
        $unit = collect($b['unites'])->firstWhere('id', $this->unite->id);

        $this->assertNotNull($unit, 'The test unit must appear in the bulletin');

        // One sequence, three controls: (12 + 16 + 8) / 3 = 12.00
        $this->assertNotNull(
            $unit['moyenneUnite'],
            'moyenneUnite must be computed, not null'
        );
        $this->assertEqualsWithDelta(12.00, $unit['moyenneUnite'], 0.01);
    }

    public function test_typed_averages_are_computed(): void
    {
        $b = $this->bulletin();

        $this->assertEqualsWithDelta(12.00, $b['moyenne_cc'], 0.01);
        $this->assertEqualsWithDelta(16.00, $b['moyenne_theorique'], 0.01);
        $this->assertEqualsWithDelta(8.00,  $b['moyenne_pratique'], 0.01);
    }

    public function test_general_average_uses_documented_weights(): void
    {
        $b = $this->bulletin();

        // CC 0.3667, Theorique 0.2666, Pratique 0.3667
        $expected = 12 * BulletinService::WEIGHT_CC
            + 16 * BulletinService::WEIGHT_THEORIQUE
            + 8  * BulletinService::WEIGHT_PRATIQUE;

        $this->assertNotNull($b['moyenne_generale']);
        $this->assertEqualsWithDelta($expected, $b['moyenne_generale'], 0.01);
    }

    public function test_partial_marks_renormalise_the_weights(): void
    {
        // Only the CC mark survives: the 0.3667 weight must be rescaled to 1.
        Note::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', [$this->theoId, $this->praId])
            ->delete();

        $b = $this->bulletin();

        $this->assertNotNull($b['moyenne_generale'], 'A CC-only student still has an average');
        $this->assertEqualsWithDelta(12.00, $b['moyenne_generale'], 0.01);
    }

    public function test_decision_follows_the_ten_point_threshold(): void
    {
        $this->assertSame('Admis(e)', $this->bulletin()['decision']);

        Note::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', [$this->ccId, $this->theoId, $this->praId])
            ->delete();
        foreach ([$this->ccId, $this->theoId, $this->praId] as $ctrlId) {
            $this->mark(8, $ctrlId);
        }

        $b = $this->bulletin();
        $this->assertLessThan(10, $b['moyenne_generale']);
        $this->assertSame('Redoublant(e)', $b['decision']);
    }
}
