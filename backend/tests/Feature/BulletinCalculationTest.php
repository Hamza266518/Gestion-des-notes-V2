<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Groupe;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\User;
use App\Services\BulletinService;
use App\Services\MoyenneService;
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
     *   Contrôle 1 = 12, Contrôle 2 = 16, Contrôle 3 = 8
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

        $this->ccId   = $this->makeControle(1);
        $this->theoId = $this->makeControle(2);
        $this->praId  = $this->makeControle(3);

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
            Examen::where('unite_id', $this->unite->id)->delete();
            $this->unite->delete();
        }

        parent::tearDown();
    }

    private function makeControle(int $numero): int
    {
        return Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => $numero,
            'nom'         => 'Contrôle ' . $numero,
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

    private function markExamen(float $valeur, string $type): void
    {
        Examen::create([
            'etudiant_id'       => $this->etudiant->id,
            'unite_id'          => $this->unite->id,
            'bloc'              => 1,
            'type'              => $type,
            'semestre'          => $this->unite->semestre,
            'annee_academique_id' => $this->annee->id,
            'valeur'            => $valeur,
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
        $unit = collect($b['unites'])->firstWhere('id', $this->unite->id);

        // MPCC: every controle counts toward the CC average regardless of type.
        $this->assertEqualsWithDelta(12.00, $unit['moyenne_cc'], 0.01);
        // Without examens there is no Théorique / Pratique component anymore.
        $this->assertNull($unit['moyenne_theorique']);
        $this->assertNull($unit['moyenne_pratique']);
    }

    public function test_theorique_and_pratique_come_from_examens(): void
    {
        $this->markExamen(18, 'theorique');
        $this->markExamen(13, 'pratique');

        $b = $this->bulletin();
        $unit = collect($b['unites'])->firstWhere('id', $this->unite->id);

        $this->assertEqualsWithDelta(18.00, $unit['moyenne_theorique'], 0.01);
        $this->assertEqualsWithDelta(13.00, $unit['moyenne_pratique'], 0.01);
    }

    public function test_general_average_uses_documented_weights(): void
    {
        // CC 12, exam Théorique 16, exam Pratique 8 — all three components present.
        $this->markExamen(16, 'theorique');
        $this->markExamen(8, 'pratique');

        $b = $this->bulletin();

        // CC 0.3667, Theorique 0.2666, Pratique 0.3667
        $expected = 12 * BulletinService::WEIGHT_CC
            + 16 * BulletinService::WEIGHT_THEORIQUE
            + 8  * BulletinService::WEIGHT_PRATIQUE;

        $this->assertNotNull($b['moyenne_generale']);
        $this->assertEqualsWithDelta($expected, $b['moyenne_generale'], 0.01);
    }

    /**
     * Regression: a promoted student must keep the transcript of the year they sat.
     *
     * ProgressionController rewrites `etudiants.annee_academique_id` on promotion,
     * and BulletinService used to filter notes through `whereHas('etudiant',
     * annee_academique_id = X)`. That expression means "is currently enrolled in
     * that year", so as soon as the student was promoted every one of their notes
     * stopped matching the previous year and the archived bulletin came back empty.
     *
     * @group regression
     */
    public function test_promoted_student_keeps_previous_year_bulletin(): void
    {
        $before = $this->bulletin();
        $this->assertNotNull($before['moyenne_generale']);

        // A second academic year with a group at the same niveau, standing in for
        // the cohort the student was promoted into.
        $nextAnnee = AnneeAcademique::create([
            'label'      => 'TEST PROMO ' . $this->annee->id,
            'is_current' => false,
        ]);
        $nextGroupe = Groupe::create([
            'niveau_id'           => $this->etudiant->groupe->niveau_id,
            'annee_academique_id' => $nextAnnee->id,
            'nom'                => 'G PROMO TEST',
            'promotion'          => 'TEST',
        ]);

        $this->etudiant->update([
            'groupe_id'           => $nextGroupe->id,
            'annee_academique_id' => $nextAnnee->id,
        ]);

        $after = app(BulletinService::class)->calculateBulletin(
            $this->etudiant->id,
            $this->annee->id,
            1
        );

        $this->assertNotNull(
            $after['moyenne_generale'],
            'The previous year transcript must survive the promotion'
        );
        $this->assertEqualsWithDelta(
            $before['moyenne_generale'],
            $after['moyenne_generale'],
            0.01
        );

        $this->etudiant->update([
            'groupe_id'           => $this->etudiant->groupe->annee_academique_id === $nextAnnee->id
                ? $nextGroupe->id
                : $this->etudiant->groupe_id,
            'annee_academique_id' => $this->annee->id,
        ]);

        $nextGroupe->delete();
        $nextAnnee->delete();
    }

    public function test_year_without_a_group_returns_an_empty_bulletin_not_an_error(): void
    {
        // A student enrolled nowhere: groupe_id is nullable (ON DELETE SET NULL), so
        // this state is reachable. It must produce a shaped result, not a 500 from
        // an unguarded $etudiant->groupe->niveau dereference.
        $orphan = Etudiant::create([
            'user_id'             => $this->etudiant->user_id ?? User::first()?->id,
            'groupe_id'           => null,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'ORPHAN TEST BULLETIN',
            'cin'                 => 'ORPHAN' . uniqid(),
            'numero_inscription'  => 'ORP' . uniqid(),
            'status'              => 'active',
        ]);

        $b = app(BulletinService::class)->calculateBulletin($orphan->id, $this->annee->id);

        $this->assertIsArray($b);
        $this->assertNull($b['moyenne_generale']);
        $this->assertNull($b['decision']);
        $this->assertSame([], $b['unites']);

        $orphan->delete();
    }

    public function test_missing_marks_count_as_zero_in_mpcc(): void
    {
        // Only the CC mark survives; the two other controls are left ungraded.
        // MPCC sums every control and divides by the total configured:
        // (12 + 0 + 0) / 3 = 4.00, and the sole CC weight rescales to 1.
        Note::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', [$this->theoId, $this->praId])
            ->delete();

        $b = $this->bulletin();

        $this->assertNotNull($b['moyenne_generale'], 'A graded student still has an average');
        $this->assertEqualsWithDelta(4.00, $b['moyenne_generale'], 0.01);
    }

    public function test_mpcc_divides_by_total_controles_of_all_sequences(): void
    {
        // Two sequences on one unit: A has 3 controls, B has 1. Every control is
        // a plain "Controle" grade for the unite-level MPCC: (10+10+10+5)/4 = 8.75.
        $u = Unite::create([
            'filiere_id'       => $this->etudiant->groupe->niveau->filiere_id,
            'nom'              => 'UNITE MULTI SEQ MPCC',
            'coefficient'      => 1,
            'nombre_controles' => 4,
            'numero_annee'     => $this->etudiant->groupe->niveau->numero,
            'semestre'         => 1,
            'ordre'            => 998,
            'is_active'        => true,
        ]);
        $seqA = Sequence::create(['unite_id' => $u->id, 'nom' => 'A', 'coefficient' => 1, 'nombre_controles' => 3]);
        $seqB = Sequence::create(['unite_id' => $u->id, 'nom' => 'B', 'coefficient' => 1, 'nombre_controles' => 1]);

        $cA1 = Controle::create(['sequence_id' => $seqA->id, 'numero' => 1, 'note_max' => 20])->id;
        $cA2 = Controle::create(['sequence_id' => $seqA->id, 'numero' => 2, 'note_max' => 20])->id;
        $cA3 = Controle::create(['sequence_id' => $seqA->id, 'numero' => 3, 'note_max' => 20])->id;
        $cB1 = Controle::create(['sequence_id' => $seqB->id, 'numero' => 1, 'note_max' => 20])->id;

        foreach ([$cA1, $cA2, $cA3] as $cid) {
            $this->mark(10, $cid);
        }
        $this->mark(5, $cB1);

        $moy = app(MoyenneService::class)->moyenneUnite($this->etudiant->id, $u->id);

        $this->assertEqualsWithDelta(8.75, $moy, 0.01);

        foreach ([$cA1, $cA2, $cA3, $cB1] as $cid) {
            Controle::where('id', $cid)->delete();
        }
        $seqA->delete();
        $seqB->delete();
        $u->delete();
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
