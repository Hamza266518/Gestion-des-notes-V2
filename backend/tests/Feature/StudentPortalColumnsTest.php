<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\SemestrePublication;
use App\Models\Unite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The grade numbering restarts for every control type, so a sequence can hold
 * cc:1, theorique:1 and pratique:1 at the same time. numero is therefore not a
 * unique identifier and the portal has to expose controle_id plus type, or the
 * student grid collapses the three grades into a single column.
 */
class StudentPortalColumnsTest extends TestCase
{
    private Etudiant $etudiant;
    private AnneeAcademique $annee;
    private Unite $unite;
    private Sequence $sequence;
    private int $ccId;
    private int $theoId;
    private int $praId;
    private int $cc2Id;

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
            'filiere_id'       => $filiereId,
            'nom'              => 'UNITE PORTAL COLUMNS',
            'coefficient'      => 2,
            'nombre_controles' => 4,
            'numero_annee'     => $niveauNumero,
            'semestre'         => 1,
            'ordre'            => 998,
            'is_active'        => true,
        ]);

        $this->sequence = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => 'SEQ PORTAL COLUMNS',
            'coefficient'      => 1,
            'nombre_controles' => 4,
        ]);

        // All four share numero 1/2 the way the real curriculum does.
        $this->ccId   = $this->makeControle('cc', 1);
        $this->cc2Id  = $this->makeControle('cc', 2);
        $this->theoId = $this->makeControle('theorique', 1);
        $this->praId  = $this->makeControle('pratique', 1);

        $this->mark(12, $this->ccId);
        $this->mark(14, $this->cc2Id);
        $this->mark(16, $this->theoId);
        $this->mark(8,  $this->praId);
    }

    protected function tearDown(): void
    {
        $ids = Note::where('etudiant_id', $this->etudiant->id)
            ->whereIn('controle_id', [$this->ccId, $this->cc2Id, $this->theoId, $this->praId])
            ->pluck('controle_id');
        if ($ids->isNotEmpty()) {
            Controle::whereIn('id', $ids)->delete();
        }
        $this->sequence->delete();
        $this->unite->delete();

        parent::tearDown();
    }

    private function makeControle(string $type, int $numero): int
    {
        return Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => $numero,
            'type'        => $type,
            'note_max'    => 20,
        ])->id;
    }

    private function mark(float $valeur, int $controleId, bool $absent = false): void
    {
        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $controleId,
            'valeur'      => $absent ? 0 : $valeur,
            'est_absent'  => $absent,
        ]);
    }

    public function test_portal_returns_one_row_per_control_including_type_and_id()
    {
        $rows = $this->portalRows();

        $mine = array_values(array_filter($rows, fn ($r) => $r['sequence_nom'] === 'SEQ PORTAL COLUMNS'));

        $this->assertCount(4, $mine, 'Every control must produce its own row');

        foreach ($mine as $row) {
            $this->assertArrayHasKey('controle_id', $row);
            $this->assertArrayHasKey('type', $row);
            $this->assertArrayHasKey('est_absent', $row);
        }
    }

    public function test_controls_sharing_a_numero_stay_distinguishable()
    {
        $mine = $this->myRows();

        // cc:1, cc:2, theorique:1, pratique:1 -> four distinct cells.
        $cells = [];
        foreach ($mine as $row) {
            $cells[] = $row['type'] . ':' . $row['controle_numero'];
        }

        $this->assertCount(4, array_unique($cells), 'Each (type, numero) pair must be its own cell');

        // Order comes from the Sequence relation (orderBy numero), so cc:1,
        // theorique:1 and pratique:1 all read numero 1 and sort together.
        sort($cells);
        $this->assertSame(['cc:1', 'cc:2', 'pratique:1', 'theorique:1'], $cells);

        // cc:1, theorique:1 and pratique:1 all share numero 1, so numero alone
        // would collapse three different grades into one column.
        $byNumero = collect($mine)->filter(fn ($r) => $r['controle_numero'] === 1);
        $this->assertCount(3, $byNumero);
        $this->assertCount(3, $byNumero->pluck('controle_id')->unique());
    }

    public function test_grade_of_each_type_is_returned_against_the_right_control()
    {
        $mine = $this->myRows();

        $values = [];
        foreach ($mine as $row) {
            $values[$row['type'] . ':' . $row['controle_numero']] = $row['valeur'];
        }

        $this->assertSame(12.0, (float) $values['cc:1']);
        $this->assertSame(14.0, (float) $values['cc:2']);
        $this->assertSame(16.0, (float) $values['theorique:1']);
        $this->assertSame(8.0,  (float) $values['pratique:1']);
    }

    public function test_absent_note_is_flagged_so_it_is_not_shown_as_a_zero()
    {
        Note::where('etudiant_id', $this->etudiant->id)
            ->where('controle_id', $this->praId)
            ->update(['valeur' => 0, 'est_absent' => true]);

        $mine = $this->myRows();
        $pratique = collect($mine)->firstWhere('controle_id', $this->praId);

        $this->assertNotNull($pratique);
        $this->assertTrue($pratique['est_absent'], 'The absence flag has to reach the client');
        $this->assertEquals(0, $pratique['valeur'], 'Absence is still stored as 0 for the averages');
    }

    public function test_raw_notes_use_a_single_query_instead_of_one_per_control()
    {
        $this->publishBothSemesters();
        $this->actingAs($this->etudiant->user);

        DB::enableQueryLog();
        $this->getJson('/api/etudiant/bulletin')->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $noteQueries = array_filter(
            $log,
            fn ($q) => str_contains($q['query'], 'from `notes`')
                && str_contains($q['query'], '`controle_id` in')
        );

        // One bulk query per published semester, never one per control. With the
        // old per-control lookup this was 4 queries per semester, and it grows
        // with the size of the curriculum.
        $this->assertCount(
            2,
            $noteQueries,
            'getRawNotes must use one bulk query per semester, not one per control'
        );
    }

    /**
     * The portal only returns the raw notes of a semester once it is published,
     * so both semesters are switched on for this student's groupe.
     */
    private function publishBothSemesters(): void
    {
        foreach (['notes_s1', 'notes_s2', 'bulletin'] as $type) {
            SemestrePublication::updateOrCreate(
                [
                    'groupe_id'         => $this->etudiant->groupe_id,
                    'annee_academique_id' => $this->etudiant->annee_academique_id,
                    'type'              => $type,
                ],
                ['is_published' => true]
            );
        }
    }

    private function portalRows(): array
    {
        $this->publishBothSemesters();

        $this->actingAs($this->etudiant->user)
            ->getJson('/api/etudiant/bulletin')
            ->assertOk()
            ->assertJsonPath('success', true);

        $data = $this->actingAs($this->etudiant->user)
            ->getJson('/api/etudiant/bulletin')
            ->json('data');

        return array_merge($data['notes_s1'] ?? [], $data['notes_s2'] ?? []);
    }

    private function myRows(): array
    {
        return array_values(array_filter(
            $this->portalRows(),
            fn ($r) => $r['sequence_nom'] === 'SEQ PORTAL COLUMNS'
        ));
    }
}