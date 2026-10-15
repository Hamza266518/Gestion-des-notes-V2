<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Where a student lands when the new year runs several groups for their level.
 *
 * The backend used to resolve the destination with `first()`, which silently
 * split a class across Groupe A and Groupe B by primary key order. It now
 * refuses to guess and answers with the candidate list instead. That list is
 * what makes the destination pickable in the UI, so the shape of the
 * `ambiguous` payload is part of the contract and is asserted here.
 *
 * Everything lives in a private filière and a private pair of years, so the
 * counts below are exact: `promoteAdmis` walks the students of the outgoing
 * year, and no other student is enrolled in a year this class created.
 *
 * Cleanup is by primary key only. The suite shares one database.
 */
class ProgressionRepartitionTest extends TestCase
{
    private User $admin;

    private AnneeAcademique $fromAnnee;
    private AnneeAcademique $toAnnee;

    private Filiere $filiere;
    private Niveau $niveau1;
    private Niveau $niveau2;

    private Groupe $groupeFrom;
    /** @var array<int, Groupe> */
    private array $groupesTo = [];

    private Etudiant $etudiant;

    private Unite $unite;
    private Sequence $sequence;
    private Controle $controle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'progression.admin@test.local')->first()
            ?? User::create([
                'name'     => 'Progression admin',
                'email'    => 'progression.admin@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $this->fromAnnee = AnneeAcademique::create(['label' => "TEST PROG FROM $suffix", 'is_current' => false]);
        $this->toAnnee   = AnneeAcademique::create(['label' => "TEST PROG TO $suffix", 'is_current' => false]);

        $this->filiere = Filiere::create([
            'nom'           => "TEST PROG FILIERE $suffix",
            'code'          => "TP$suffix",
            'section'       => 'Test',
            'nombre_annees' => 3,
        ]);

        $this->niveau1 = Niveau::create(['filiere_id' => $this->filiere->id, 'numero' => 1]);
        $this->niveau2 = Niveau::create(['filiere_id' => $this->filiere->id, 'numero' => 2]);

        $this->groupeFrom = Groupe::create([
            'niveau_id'           => $this->niveau1->id,
            'annee_academique_id' => $this->fromAnnee->id,
            'nom'                 => "FROM $suffix",
            'promotion'           => '',
        ]);

        // Two groups per level in the new year: the situation the admin has to
        // arbitrate, for a redoublant (same level) and for a promotion (next).
        foreach ([$this->niveau1, $this->niveau2] as $niveau) {
            foreach (['A', 'B'] as $suffixe) {
                $this->groupesTo[] = Groupe::create([
                    'niveau_id'           => $niveau->id,
                    'annee_academique_id' => $this->toAnnee->id,
                    'nom'                 => "N{$niveau->numero}$suffixe $suffix",
                    'promotion'           => '',
                ]);
            }
        }

        $this->etudiant = Etudiant::create([
            'user_id'             => User::first()?->id,
            'groupe_id'           => $this->groupeFrom->id,
            'annee_academique_id' => $this->fromAnnee->id,
            'nom_prenom'          => "Etudiant Progression $suffix",
            'cin'                 => "TP$suffix",
            'numero_inscription'  => "TPI$suffix",
            'status'              => 'active',
        ]);

        // One continuous-control mark of 15/20 is enough for an "Admis(e)"
        // bulletin: with no exam marks the general average renormalises onto the
        // CC weight alone.
        $this->unite = Unite::create([
            'filiere_id'   => $this->filiere->id,
            'nom'          => "UNITE PROG $suffix",
            'coefficient'  => 1,
            'numero_annee' => 1,
            'semestre'     => 1,
            'ordre'        => 999,
            'is_active'    => true,
        ]);

        $this->sequence = Sequence::create([
            'unite_id'         => $this->unite->id,
            'nom'              => "SEQ PROG $suffix",
            'coefficient'      => 1,
            'nombre_controles' => 1,
        ]);

        $this->controle = Controle::create([
            'sequence_id' => $this->sequence->id,
            'numero'      => 1,
            'note_max'    => 20,
        ]);

        Note::create([
            'etudiant_id' => $this->etudiant->id,
            'controle_id' => $this->controle->id,
            'valeur'      => 15,
        ]);
    }

    protected function tearDown(): void
    {
        // Reverse dependency order, by id.
        Note::where('etudiant_id', $this->etudiant->id)->delete();
        Controle::where('id', $this->controle->id)->delete();
        Sequence::where('id', $this->sequence->id)->delete();
        Unite::where('id', $this->unite->id)->delete();
        Etudiant::where('id', $this->etudiant->id)->forceDelete();
        Groupe::where('id', $this->groupeFrom->id)->delete();
        foreach ($this->groupesTo as $groupe) {
            Groupe::where('id', $groupe->id)->delete();
        }
        Niveau::whereIn('id', [$this->niveau1->id, $this->niveau2->id])->delete();
        Filiere::where('id', $this->filiere->id)->delete();
        AnneeAcademique::whereIn('id', [$this->fromAnnee->id, $this->toAnnee->id])->delete();

        parent::tearDown();
    }

    private function asAdmin()
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    /** The two groups the new year offers for a level. */
    private function candidats(int $niveauId): array
    {
        return array_values(array_filter(
            $this->groupesTo,
            fn(Groupe $g) => (int) $g->niveau_id === $niveauId
        ));
    }

    public function test_a_redoublant_with_several_groups_is_reported_with_its_candidates(): void
    {
        $res = $this->asAdmin()->postJson('/api/admin/progression/confirm-redoublants', [
            'etudiant_ids' => [$this->etudiant->id],
            'to_annee_id'  => $this->toAnnee->id,
        ]);

        $res->assertOk();
        $this->assertSame(0, $res->json('confirmed'), 'Nothing may be confirmed while the choice is open');

        $ambiguous = $res->json('ambiguous');
        $this->assertCount(1, $ambiguous, 'Exactly one student needs a destination');

        $entry = $ambiguous[0];
        $this->assertSame($this->etudiant->id, $entry['etudiant_id']);
        $this->assertSame($this->niveau1->id, $entry['niveau_id']);

        $expected = array_map(fn(Groupe $g) => $g->id, $this->candidats($this->niveau1->id));
        $this->assertSame($expected, array_column($entry['groupes'], 'id'), 'Both candidates must be offered');

        $this->assertSame(
            $this->groupeFrom->id,
            $this->etudiant->fresh()->groupe_id,
            'An unresolved student must stay where they are'
        );
    }

    public function test_a_named_destination_is_honoured(): void
    {
        $cible = $this->candidats($this->niveau1->id)[1];

        $res = $this->asAdmin()->postJson('/api/admin/progression/confirm-redoublants', [
            'etudiant_ids' => [$this->etudiant->id],
            'to_annee_id'  => $this->toAnnee->id,
            'groupe_ids'   => [$this->etudiant->id => $cible->id],
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('confirmed'));
        $this->assertSame([], $res->json('ambiguous'), 'A named destination leaves nothing to arbitrate');

        $etudiant = $this->etudiant->fresh();
        $this->assertSame($cible->id, $etudiant->groupe_id);
        $this->assertSame($this->toAnnee->id, $etudiant->annee_academique_id);
    }

    public function test_a_destination_from_another_year_is_refused(): void
    {
        $res = $this->asAdmin()->postJson('/api/admin/progression/confirm-redoublants', [
            'etudiant_ids' => [$this->etudiant->id],
            'to_annee_id'  => $this->toAnnee->id,
            'groupe_ids'   => [$this->etudiant->id => $this->groupeFrom->id],
        ]);

        $res->assertOk();
        $this->assertSame(0, $res->json('confirmed'), 'A group of the outgoing year is not a destination');
        $this->assertNotEmpty($res->json('errors'));
        $this->assertSame($this->groupeFrom->id, $this->etudiant->fresh()->groupe_id);
    }

    public function test_a_promotion_with_several_groups_is_reported_and_moves_nobody(): void
    {
        $res = $this->asAdmin()->postJson('/api/admin/progression/promote-admis', [
            'from_annee_id' => $this->fromAnnee->id,
            'to_annee_id'   => $this->toAnnee->id,
        ]);

        $res->assertOk();
        $this->assertSame(0, $res->json('promoted'), 'Nobody may be promoted while a choice is open');
        $this->assertSame(0, $res->json('graduated'));

        $ambiguous = $res->json('ambiguous');
        $this->assertCount(1, $ambiguous);

        $entry = $ambiguous[0];
        $this->assertSame($this->etudiant->id, $entry['etudiant_id']);
        $this->assertSame(2, $entry['niveau_numero'], 'The destination level is the next one');

        $expected = array_map(fn(Groupe $g) => $g->id, $this->candidats($this->niveau2->id));
        $this->assertSame($expected, array_column($entry['groupes'], 'id'));

        $this->assertSame(
            $this->fromAnnee->id,
            $this->etudiant->fresh()->annee_academique_id,
            'An unresolved student stays in the outgoing year'
        );
    }

    public function test_a_promotion_honours_a_named_destination(): void
    {
        $cible = $this->candidats($this->niveau2->id)[0];

        $res = $this->asAdmin()->postJson('/api/admin/progression/promote-admis', [
            'from_annee_id' => $this->fromAnnee->id,
            'to_annee_id'   => $this->toAnnee->id,
            'groupe_ids'    => [$this->etudiant->id => $cible->id],
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('promoted'));
        $this->assertSame([], $res->json('ambiguous'));

        $etudiant = $this->etudiant->fresh();
        $this->assertSame($cible->id, $etudiant->groupe_id);
        $this->assertSame($this->toAnnee->id, $etudiant->annee_academique_id);
    }

    public function test_a_second_pass_after_the_choice_is_idempotent(): void
    {
        $cible = $this->candidats($this->niveau2->id)[0];
        $payload = [
            'from_annee_id' => $this->fromAnnee->id,
            'to_annee_id'   => $this->toAnnee->id,
            'groupe_ids'    => [$this->etudiant->id => $cible->id],
        ];

        $this->asAdmin()->postJson('/api/admin/progression/promote-admis', $payload)->assertOk();
        $res = $this->asAdmin()->postJson('/api/admin/progression/promote-admis', $payload);

        $res->assertOk();
        $this->assertSame(0, $res->json('promoted'), 'A student already moved is no longer in the outgoing year');
        $this->assertSame([], $res->json('ambiguous'));
        $this->assertSame($cible->id, $this->etudiant->fresh()->groupe_id);
    }
}
