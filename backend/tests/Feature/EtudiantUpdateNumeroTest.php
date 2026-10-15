<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The N° inscription is only hand-editable for students in their final year
 * (année diplômante: niveau.numero === filiere.nombre_annees), and it must
 * stay unique.
 */
class EtudiantUpdateNumeroTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private Filiere $filiere;
    private Niveau $niveauPremier;
    private Niveau $niveauFinal;
    private Groupe $groupePremier;
    private Groupe $groupeFinal;
    private array $tracked = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::firstWhere('role', 'admin')
            ?? User::create([
                'name'     => 'Numero edit admin',
                'email'    => 'numero.edit.admin@sweep.test',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);
        $this->track('users', $this->admin->id);

        $this->annee = AnneeAcademique::where('is_current', true)->first() ?? AnneeAcademique::first();

        $this->filiere = Filiere::create([
            'nom'            => 'NUMEDIT ' . bin2hex(random_bytes(3)),
            'code'           => 'NED' . bin2hex(random_bytes(3)),
            'section'        => 'Section NUMEDIT',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 3,
        ]);
        $this->track('filieres', $this->filiere->id);

        $this->niveauPremier = Niveau::create(['filiere_id' => $this->filiere->id, 'numero' => 1]);
        $this->niveauFinal   = Niveau::create(['filiere_id' => $this->filiere->id, 'numero' => 3]);
        $this->track('niveaux', $this->niveauPremier->id);
        $this->track('niveaux', $this->niveauFinal->id);

        $this->groupePremier = Groupe::create([
            'niveau_id'           => $this->niveauPremier->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'NUMEDIT-G1',
            'promotion'           => 'NUMEDIT',
        ]);
        $this->groupeFinal = Groupe::create([
            'niveau_id'           => $this->niveauFinal->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'NUMEDIT-G3',
            'promotion'           => 'NUMEDIT',
        ]);
        $this->track('groupes', $this->groupePremier->id);
        $this->track('groupes', $this->groupeFinal->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->tracked['etudiants'] ?? [] as $id) {
            Etudiant::whereKey($id)->forceDelete();
        }
        foreach ($this->tracked['users'] ?? [] as $id) {
            \DB::table('users')->where('id', $id)->delete();
        }
        foreach ($this->tracked['groupes'] ?? [] as $id) {
            \DB::table('groupes')->where('id', $id)->delete();
        }
        foreach ($this->tracked['niveaux'] ?? [] as $id) {
            \DB::table('niveaux')->where('id', $id)->delete();
        }
        foreach ($this->tracked['filieres'] ?? [] as $id) {
            \DB::table('filieres')->where('id', $id)->delete();
        }
        parent::tearDown();
    }

    private function track(string $table, int $id): void
    {
        $this->tracked[$table][] = $id;
    }

    private function etudiantDans(Groupe $groupe, array $extra = []): Etudiant
    {
        $user = User::create([
            'name'     => 'NUMEDIT STAGIAIRE',
            'email'    => 'numedit.' . bin2hex(random_bytes(5)) . '@sweep.test',
            'password' => Hash::make('secret123'),
            'role'     => 'etudiant',
        ]);
        $this->track('users', $user->id);

        $etudiant = Etudiant::create(array_merge([
            'user_id'             => $user->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'status'              => 'active',
            'nom_prenom'          => 'NUMEDIT STAGIAIRE',
            'cin'                 => 'NE' . strtoupper(bin2hex(random_bytes(5))),
            'numero_inscription'  => 'NED-' . bin2hex(random_bytes(4)),
        ], $extra));
        $this->track('etudiants', $etudiant->id);
        return $etudiant;
    }

    private function payload(Etudiant $etudiant, array $extra = []): array
    {
        return array_merge([
            'nom_prenom'       => $etudiant->nom_prenom,
            'cin'              => $etudiant->cin,
            'groupe_id'        => $etudiant->groupe_id,
            'date_naissance'   => '1998-05-15',
            'lieu_naissance'   => 'RABAT',
            'nationalite'      => 'Marocaine',
            'date_inscription' => '2025-09-15',
        ], $extra);
    }

    public function test_numero_is_editable_in_the_final_year(): void
    {
        $etudiant = $this->etudiantDans($this->groupeFinal);

        $this->actingAs($this->admin)
            ->putJson('/api/admin/etudiants/' . $etudiant->id, $this->payload($etudiant, [
                'numero_inscription' => '2025/9001',
            ]))
            ->assertOk();

        $etudiant->refresh();
        $this->assertSame('2025/9001', $etudiant->numero_inscription);
        $this->assertSame('2025/9001', $etudiant->numero_inscription_ar);
    }

    public function test_numero_is_refused_before_the_final_year(): void
    {
        $etudiant = $this->etudiantDans($this->groupePremier);

        $this->actingAs($this->admin)
            ->putJson('/api/admin/etudiants/' . $etudiant->id, $this->payload($etudiant, [
                'numero_inscription' => '2025/9001',
            ]))
            ->assertStatus(422);

        $etudiant->refresh();
        $this->assertNotSame('2025/9001', $etudiant->numero_inscription);
    }

    public function test_numero_must_stay_unique(): void
    {
        $cible = $this->etudiantDans($this->groupeFinal, ['numero_inscription' => '2025/4242']);
        $autre = $this->etudiantDans($this->groupeFinal, ['numero_inscription' => '2025/7777']);

        $this->actingAs($this->admin)
            ->putJson('/api/admin/etudiants/' . $cible->id, $this->payload($cible, [
                'numero_inscription' => $autre->numero_inscription,
            ]))
            ->assertStatus(422);

        $cible->refresh();
        $this->assertSame('2025/4242', $cible->numero_inscription);
    }
}