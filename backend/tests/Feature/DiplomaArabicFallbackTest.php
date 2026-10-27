<?php

namespace Tests\Feature;

use App\Models\AnneeAcademique;
use App\Models\Diplome;
use App\Models\Etudiant;
use App\Models\Filiere;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\User;
use App\Services\DiplomaDocxService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The Arabic half of a diploma must never print Latin text: when a student's
 * Arabic fields are empty, dataFor() derives them (Moroccan city dictionary,
 * Arabic month names, مغربية for a Moroccan nationality) instead of echoing
 * the French value.
 */
class DiplomaArabicFallbackTest extends TestCase
{
    private User $admin;
    private AnneeAcademique $annee;
    private array $createdDiplomes = [];
    private array $createdEtudiants = [];
    private array $createdGroupes = [];
    private array $createdNiveaux = [];
    private array $createdFilieres = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::where('email', 'diplome.ar@test.local')->first()
            ?? User::create([
                'name'     => 'Diplome ar admin',
                'email'    => 'diplome.ar@test.local',
                'password' => Hash::make('secret123'),
                'role'     => 'admin',
            ]);

        $this->annee = AnneeAcademique::where('is_current', true)->first()
            ?? AnneeAcademique::first();

        $this->assertNotNull($this->annee, 'An academic year is required to test diplomas');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdDiplomes as $diplome) {
            Diplome::whereKey($diplome->id)->delete();
        }
        foreach ($this->createdEtudiants as $etudiant) {
            Etudiant::whereKey($etudiant->id)->delete();
        }
        foreach ($this->createdGroupes as $groupe) {
            Groupe::whereKey($groupe->id)->delete();
        }
        foreach ($this->createdNiveaux as $niveau) {
            Niveau::whereKey($niveau->id)->delete();
        }
        foreach ($this->createdFilieres as $filiere) {
            Filiere::whereKey($filiere->id)->delete();
        }

        parent::tearDown();
    }

    /** A student whose Arabic columns are empty on purpose. */
    private function makeDiplomeAvecArabeVide(): Diplome
    {
        $filiere = Filiere::create([
            'nom'            => 'FIL AR ' . bin2hex(random_bytes(3)),
            'code'           => 'FAR' . bin2hex(random_bytes(3)),
            'section'        => 'SECTION',
            'type_formation' => 'Technicien',
            'nombre_annees'  => 1,
        ]);
        $this->createdFilieres[] = $filiere;

        $niveau = Niveau::create(['filiere_id' => $filiere->id, 'numero' => 1]);
        $this->createdNiveaux[] = $niveau;

        $groupe = Groupe::create([
            'niveau_id'           => $niveau->id,
            'annee_academique_id' => $this->annee->id,
            'nom'                 => 'GRP AR ' . bin2hex(random_bytes(3)),
            'promotion'           => '2024/2025',
        ]);
        $this->createdGroupes[] = $groupe;

        $etudiant = Etudiant::create([
            'user_id'             => $this->admin->id,
            'groupe_id'           => $groupe->id,
            'annee_academique_id' => $this->annee->id,
            'nom_prenom'          => 'ETUDIANT AR',
            'cin'                 => 'AR' . strtoupper(bin2hex(random_bytes(5))),
            'numero_inscription'  => 'AR' . bin2hex(random_bytes(6)),
            'lieu_naissance'      => 'Fès',
            'nationalite'         => 'Marocaine',
            'date_naissance'      => '1998-05-15',
            'date_inscription'    => '2024-09-15',
            'status'              => 'graduate',
        ]);
        $this->createdEtudiants[] = $etudiant;

        $diplome = Diplome::create([
            'etudiant_id'         => $etudiant->id,
            'annee_academique_id' => $this->annee->id,
            'moyenne_generale'    => 14.25,
            'mention'             => 'Bien',
            'is_printed'          => false,
        ]);
        $this->createdDiplomes[] = $diplome;

        return $diplome;
    }

    public function test_arabic_fields_never_fall_back_to_latin_text(): void
    {
        $data = app(DiplomaDocxService::class)->dataFor($this->makeDiplomeAvecArabeVide());

        $this->assertSame('مغربية', $data['nationalite_ar']);
        $this->assertSame('فاس', $data['lieu_naissance_ar']);
        $this->assertSame('15/05/1998', $data['date_naissance_ar']);
        $this->assertSame('15/09/2024', $data['date_inscription_ar']);
        $this->assertSame('', $data['nom_ar']);
    }

    public function test_the_arabic_side_prints_the_french_registration_number(): void
    {
        $diplome = $this->makeDiplomeAvecArabeVide();
        // Even when an Arabic-digit inscription number is stored, the diploma
        // prints the same Latin number as the French side (calibration keeps the
        // field on the Arabic half, but it must read the French value).
        $diplome->etudiant()->update([
            'numero_inscription'    => '2025/9001',
            'numero_inscription_ar' => '٢٠٢٥/٩٠٠١',
        ]);

        $data = app(DiplomaDocxService::class)->dataFor($diplome->fresh());

        $this->assertSame('2025/9001', $data['numero_inscription_ar']);
    }

    public function test_stored_arabic_values_are_kept_verbatim(): void
    {
        $diplome = $this->makeDiplomeAvecArabeVide();
        $diplome->etudiant()->update([
            'nom_ar'              => 'المغربي أمين',
            'nationalite_ar'      => 'المغربية',
            'lieu_naissance_ar'   => 'فاس',
            'date_naissance_ar'   => '15 ماي 1998',
            'date_inscription_ar' => '15 شتنبر 2024',
        ]);

        $data = app(DiplomaDocxService::class)->dataFor($diplome->fresh());

        $this->assertSame('المغربي أمين', $data['nom_ar']);
        $this->assertSame('المغربية', $data['nationalite_ar']);
        $this->assertSame('فاس', $data['lieu_naissance_ar']);
    }

    public function test_foreign_nationality_leaves_the_arabic_side_blank(): void
    {
        $diplome = $this->makeDiplomeAvecArabeVide();
        $diplome->etudiant()->update([
            'nationalite' => 'Portugaise',
            'lieu_naissance_ar' => 'لشبونة',
        ]);

        $data = app(DiplomaDocxService::class)->dataFor($diplome->fresh());

        $this->assertSame('', $data['nationalite_ar']);
        $this->assertSame('لشبونة', $data['lieu_naissance_ar']);
    }
}