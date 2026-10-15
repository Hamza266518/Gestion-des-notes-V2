<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Services\BulletinService;
use App\Services\CurriculumAuditService;
use App\Services\MoyenneService;
use App\Support\Curriculum\OfficialCurriculum;
use Tests\TestCase;

/**
 * Guards the curriculum rules taken from the official documents.
 *
 * Key rule under test: a sequence's `coefficient` column is NOT a curriculum
 * weight. It only mirrors `nombre_controles`. The unite-level MPCC therefore
 * sums every controle of every sequence and divides by the total controles
 * configured, and only the unit coefficient may scale the unit in the general
 * average.
 */
class CurriculumCalculationTest extends TestCase
{
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    /** @var array<int, Sequence> */
    private array $sequences = [];
    /** @var array<int, int> */
    private array $controles = [];

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

        $this->unite = Unite::create([
            'filiere_id'   => $this->etudiant->groupe->niveau->filiere_id,
            'nom'          => 'UNITE CURRICULUM TEST',
            'coefficient'  => 3,
            'numero_annee' => $this->etudiant->groupe->niveau->numero,
            'semestre'     => 1,
            'ordre'        => 998,
            'is_active'    => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->controles as $id) {
            Controle::where('id', $id)->delete();
        }
        foreach ($this->sequences as $seq) {
            Sequence::where('id', $seq->id)->delete();
        }
        if (isset($this->unite)) {
            Unite::where('id', $this->unite->id)->delete();
        }

        parent::tearDown();
    }

    private function addSequence(string $nom, int $coefficient, int $nombreControles): Sequence
    {
        $seq = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => $nom,
            'coefficient'      => $coefficient,
            'nombre_controles' => $nombreControles,
        ]);
        $this->sequences[] = $seq;
        return $seq;
    }

    private function addControle(Sequence $seq, ?float $valeur): int
    {
        $ctrl = Controle::create([
            'sequence_id' => $seq->id,
            'numero'      => 1,
            'note_max'    => 20,
        ]);
        $this->controles[] = $ctrl->id;

        if ($valeur !== null) {
            Note::create([
                'etudiant_id' => $this->etudiant->id,
                'controle_id' => $ctrl->id,
                'valeur'      => $valeur,
            ]);
        }

        return $ctrl->id;
    }

    private function moyenneUnite(): ?float
    {
        return app(MoyenneService::class)->moyenneUnite(
            $this->etudiant->id,
            $this->unite->id,
            $this->annee->id
        );
    }

    public function test_sequences_are_averaged_with_equal_weight(): void
    {
        $heavy = $this->addSequence('SEQ LOURD', 5, 5);
        $light = $this->addSequence('SEQ LEGER', 1, 1);

        $this->addControle($heavy, 20.0);
        $this->addControle($light, 4.0);

        // Equal sequence weighting -> (20 + 4) / 2 = 12
        // Coefficient weighting would give (20*5 + 4*1) / 6 = 17.33
        $this->assertEqualsWithDelta(12.00, $this->moyenneUnite(), 0.01);
    }

    public function test_a_sequence_with_a_zero_coefficient_is_still_counted(): void
    {
        $normal = $this->addSequence('SEQ NORMAL', 2, 2);
        $zeroed = $this->addSequence('SEQ COEF ZERO', 0, 0);

        $this->addControle($normal, 10.0);
        $this->addControle($zeroed, 20.0);

        // The zero coefficient must not drop the sequence: (10 + 20) / 2 = 15
        $this->assertEqualsWithDelta(15.00, $this->moyenneUnite(), 0.01);
    }

    public function test_cc_average_uses_equal_sequence_weight(): void
    {
        $heavy = $this->addSequence('SEQ LOURD CC', 4, 4);
        $light = $this->addSequence('SEQ LEGER CC', 1, 1);

        $this->addControle($heavy, 20.0);
        $this->addControle($light, 4.0);

        $b = app(BulletinService::class)->calculateBulletin($this->etudiant->id, $this->annee->id, 1);
        $unit = collect($b['unites'])->firstWhere('id', $this->unite->id);

        $this->assertNotNull($unit);
        $this->assertEqualsWithDelta(12.00, $unit['moyenne_cc'], 0.01);
    }

    public function test_unit_coefficient_is_used_for_the_general_average(): void
    {
        // Two units of the same filiere/year/semestre with very different weights.
        $other = Unite::create([
            'filiere_id'   => $this->unite->filiere_id,
            'nom'          => 'UNITE CURRICULUM TEST PONDREE',
            'coefficient'  => 1,
            'numero_annee' => $this->unite->numero_annee,
            'semestre'     => 1,
            'ordre'        => 997,
            'is_active'    => true,
        ]);

        try {
            $seqA = $this->addSequence('SEQ A', 1, 1);
            $this->addControle($seqA, 20.0);

            $seqB = Sequence::create([
                'unite_id'         => $other->id,
                'nom'              => 'SEQ B',
                'coefficient'      => 1,
                'nombre_controles' => 1,
            ]);
            $this->sequences[] = $seqB;
            $this->addControle($seqB, 0.0);

            $generale = app(MoyenneService::class)->moyenneGenerale(
                $this->etudiant->id,
                1,
                $this->annee->id
            );

            // Coefficient 3 on 20 and coefficient 1 on 0 -> 60 / 4 = 15
            $this->assertEqualsWithDelta(15.00, $generale, 0.01);
        } finally {
            foreach ($this->controles as $id) {
                Controle::where('id', $id)->delete();
            }
            $this->controles = [];
            Sequence::whereIn('id', array_map(fn(Sequence $s) => $s->id, $this->sequences))->delete();
            $this->sequences = [];
            Unite::where('id', $other->id)->delete();
        }
    }

    public function test_general_average_ignores_other_years_of_the_same_filiere(): void
    {
        $autreAnnee = Unite::create([
            'filiere_id'   => $this->unite->filiere_id,
            'nom'          => 'UNITE AUTRE ANNEE',
            'coefficient'  => 50,
            'numero_annee' => $this->unite->numero_annee + 1,
            'semestre'     => 1,
            'ordre'        => 996,
            'is_active'    => true,
        ]);

        try {
            $seq = $this->addSequence('SEQ ANNEE COURANTE', 1, 1);
            $this->addControle($seq, 12.0);

            $generale = app(MoyenneService::class)->moyenneGenerale(
                $this->etudiant->id,
                1,
                $this->annee->id
            );

            // The 50-coefficient unit belongs to another year and must be excluded.
            $this->assertEqualsWithDelta(12.00, $generale, 0.01);
        } finally {
            foreach ($this->controles as $id) {
                Controle::where('id', $id)->delete();
            }
            $this->controles = [];
            Sequence::whereIn('id', array_map(fn(Sequence $s) => $s->id, $this->sequences))->delete();
            $this->sequences = [];
            Unite::where('id', $autreAnnee->id)->delete();
        }
    }

    public function test_bulletin_controls_expose_a_label_and_the_scale(): void
    {
        $seq = $this->addSequence('SEQ LIBELLE', 1, 1);
        $this->addControle($seq, 14.0);

        $b = app(BulletinService::class)->calculateBulletin($this->etudiant->id, $this->annee->id, 1);
        $unit = collect($b['unites'])->firstWhere('id', $this->unite->id);
        $ctrl = $unit['sequences'][0]['controles'][0];

        $this->assertNotEmpty($ctrl['nom'], 'A control must have a printable label');
        $this->assertEquals(20, (int) $ctrl['note_max']);
        $this->assertEqualsWithDelta(14.0, (float) $ctrl['valeur'], 0.01);
    }

    public function test_official_reference_has_no_unusable_coefficient(): void
    {
        foreach (OfficialCurriculum::coefficients() as $code => $years) {
            foreach ($years as $annee => $list) {
                if ($list === null) {
                    continue;
                }
                foreach ($list as $o) {
                    $this->assertGreaterThan(
                        0,
                        $o['coefficient'],
                        "{$code} an{$annee} '{$o['nom']}' must have a positive coefficient"
                    );
                }
            }
        }
    }

    public function test_ia1_is_explicitly_marked_unresolved(): void
    {
        // The official IA1 table duplicates the AS table and must never be applied.
        $this->assertArrayHasKey('IA:1', OfficialCurriculum::unresolved());
        $this->assertNull(OfficialCurriculum::coefficients()['IA'][1]);
    }

    public function test_final_mark_weights_match_the_official_formula(): void
    {
        $this->assertSame(
            ['MPCC' => 0.30, 'MPEFCFT' => 0.20, 'MPEFCFP' => 0.30, 'NSTI' => 0.20],
            OfficialCurriculum::FINAL_WEIGHTS
        );
        $this->assertEqualsWithDelta(1.0, array_sum(OfficialCurriculum::FINAL_WEIGHTS), 0.0001);
    }

    public function test_stage_coefficient_is_four_in_every_programme(): void
    {
        $this->assertSame(4, OfficialCurriculum::STAGE_COEFFICIENT);
    }

    public function test_audit_service_runs_and_separates_unresolved_programmes(): void
    {
        $report = app(CurriculumAuditService::class)->audit();

        $total = array_sum($report['summary']);
        $this->assertSame(count($report['unites']), $total, 'Every unit must be classified exactly once');

        $this->assertArrayHasKey('unresolved', $report['summary']);
        $this->assertGreaterThan(0, $report['summary']['unresolved'], 'IA1 must be reported as unresolved');

        // Every unit that has an official counterpart must carry a status the
        // migration knows how to act on: change, ok, unresolved or no_source.
        $allowed = ['change', 'ok', 'unresolved', 'no_source'];
        foreach ($report['unites'] as $row) {
            $this->assertContains($row['status'], $allowed);
            if ($row['status'] === 'change') {
                $this->assertNotNull($row['official'], "Unit {$row['id']} has no official coefficient");
                $this->assertNotSame($row['current'], $row['official']);
            }
        }
    }

    public function test_sequence_audit_flags_control_count_drift(): void
    {
        $audit = app(CurriculumAuditService::class)->sequenceAudit();

        $this->assertArrayHasKey('nbc_mismatch', $audit['summary']);
        $this->assertGreaterThan(0, $audit['summary']['coef_equals_nbc'], 'Sequence coefficients mirror nombre_controles');
    }
}
