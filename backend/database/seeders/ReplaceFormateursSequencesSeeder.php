<?php

namespace Database\Seeders;

use App\Models\Formateur;
use App\Models\Filiere;
use App\Models\Niveau;
use App\Models\Unite;
use App\Models\Sequence;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ReplaceFormateursSequencesSeeder extends Seeder
{
    protected $formateurMap = [];

    public function run(): void
    {
        $this->command->info('Removing all old formateurs, unites, sequences...');

        DB::transaction(function () {
            $this->deleteAllOldData();
            $this->createAllFormateurs();
            $this->createASData();
            $this->createIA1Data();
            $this->createIA2Data();
            $this->createIP1Data();
            $this->createIP2Data();
            $this->createIP3Data();
        });

        $this->printSummary();
    }

    protected function deleteAllOldData(): void
    {
        $sequenceIds = DB::table('sequences')->pluck('id')->toArray();

        if (!empty($sequenceIds)) {
            $controleIds = DB::table('controles')->whereIn('sequence_id', $sequenceIds)->pluck('id')->toArray();
            if (!empty($controleIds)) {
                DB::table('notes')->whereIn('controle_id', $controleIds)->delete();
            }
            DB::table('controles')->whereIn('sequence_id', $sequenceIds)->delete();
        }

        DB::table('formateur_sequences')->delete();
        DB::table('formateur_unites')->delete();

        $formateurUserIds = DB::table('formateurs')->pluck('user_id')->toArray();
        $formateurIds = DB::table('formateurs')->pluck('id')->toArray();

        $filiereIds = DB::table('filieres')->pluck('id')->toArray();
        if (!empty($filiereIds)) {
            DB::table('unites')->whereIn('filiere_id', $filiereIds)->delete();
        }

        if (!empty($formateurIds)) {
            DB::table('formateurs')->whereIn('id', $formateurIds)->delete();
        }
        if (!empty($formateurUserIds)) {
            DB::table('users')->whereIn('id', $formateurUserIds)->delete();
        }
    }

    protected function createFormateur($nom, $email, $code): Formateur
    {
        $user = User::create([
            'name'     => $nom,
            'email'    => $email,
            'password' => Hash::make($code),
            'password_encrypted' => Crypt::encryptString($code),
            'password_original_encrypted' => Crypt::encryptString($code),
            'role'     => 'formateur',
        ]);

        $formateur = Formateur::create(['user_id' => $user->id]);
        $this->formateurMap[$code] = $formateur;
        return $formateur;
    }

    protected function createAllFormateurs(): void
    {
        $this->createFormateur('Mme. Fatima-Zahra TAHRI', 'tahri@ifp.ma', '13');
        $this->createFormateur('Mme. SERGHOUCHNI', 'serghouchni@ifp.ma', '18');
        $this->createFormateur('Mme. LOUKILI', 'loukili@ifp.ma', '28');
        $this->createFormateur('M. Anass KAICHOUH', 'kaichouh@ifp.ma', '21');
        $this->createFormateur('M. BOULGHALEGH', 'boulghalegh@ifp.ma', '23');
        $this->createFormateur('Khalid BIHHADI', 'bihhadi@ifp.ma', '09');
        $this->createFormateur('M. Ouadie EL HAMEL', 'elhamel@ifp.ma', '22');
        $this->createFormateur('Mme. Salima RAHMOUNI', 'rahmouni@ifp.ma', '19');
        $this->createFormateur('M. Soulaymane BELATARIS', 'belataris@ifp.ma', '24');
        $this->createFormateur('M. Ibrahim HATHOUT', 'hathout@ifp.ma', '25');
        $this->createFormateur('M. Ayoub MENSOUB', 'mensoub@ifp.ma', '14');
        $this->createFormateur('Mme. Soukaina AISSAOUI', 'aissaoui@ifp.ma', '10');
        $this->createFormateur('Mme. Yamina AMMARI', 'ammari@ifp.ma', '02');
        $this->createFormateur('Mme. Fatima Zahra SAIDI', 'saidi@ifp.ma', '05');
        $this->createFormateur('Dr. Imane STAILI', 'staili@ifp.ma', '27');
        $this->createFormateur('Mme. Maryem NEJJARI', 'nejjari@ifp.ma', '16');
    }

    protected function createUnite($filiereCode, $niveauNum, $nom, $coef, $semestre, $ordre): Unite
    {
        $filiere = Filiere::where('code', $filiereCode)->firstOrFail();
        $unite = Unite::create([
            'filiere_id'    => $filiere->id,
            'nom'           => $nom,
            'coefficient'   => $coef,
            'numero_annee'  => $niveauNum,
            'semestre'      => $semestre,
            'ordre'         => $ordre,
            'is_active'     => true,
        ]);
        return $unite;
    }

    protected function createSequence(Unite $unite, $nom, $coef, $ctrls, $ordre, ?Formateur $formateur = null, $heures = 0): Sequence
    {
        $sequence = Sequence::create([
            'unite_id'           => $unite->id,
            'nom'                => $nom,
            'coefficient'        => $coef,
            'nombre_controles'   => $ctrls,
            'ordre'              => $ordre,
            'is_active'          => true,
        ]);

        if ($formateur && $heures > 0) {
            $formateur->sequences()->attach($sequence->id, ['masse_horaire' => $heures]);
        }

        return $sequence;
    }

    // ═══════════════════════════════════════════════════
    // AS - Aide-Soignant (1ère année)
    // ═══════════════════════════════════════════════════

    protected function createASData(): void
    {
        $tahri      = $this->formateurMap['13'];
        $serghouchni = $this->formateurMap['18'];
        $loukili    = $this->formateurMap['28'];
        $kaichouh   = $this->formateurMap['21'];
        $boulghalegh = $this->formateurMap['23'];
        $bihhadi    = $this->formateurMap['09'];
        $elhameL    = $this->formateurMap['22'];

        // Unite 1 - M. BIHHADI
        $u1 = $this->createUnite('AS', 1, "Introduction aux soins d'hygiène et du confort du malade", 2, 1, 1);
        $this->createSequence($u1, "Introduction", 2, 2, 1, $bihhadi, 20);

        // Unite 2 - Mme TAHRI
        $u2 = $this->createUnite('AS', 1, "Hygiène : individuelle, envir, hospitalière", 2, 1, 2);
        $this->createSequence($u2, "Hygiène individuelle et collective", 1, 1, 1, $tahri, 12);
        $this->createSequence($u2, "Hygiène hospitalière", 1, 1, 2, $tahri, 15);

        // Unite 3 - Mme TAHRI (1/8,2/8,4/8,5/8,7/8,8/8) + BIHHADI (6/8) + SERGHOUCHNI (3/8)
        $u3 = $this->createUnite('AS', 1, "Surveillance de l'état d'hygiène et confort du malade", 10, 1, 3);
        $this->createSequence($u3, "Technique de la literie", 3, 3, 1, $tahri, 30);
        $this->createSequence($u3, "Hygiène de l'adulte", 1, 1, 2, $tahri, 14);
        $this->createSequence($u3, "Hygiène du nourrisson", 2, 2, 3, $serghouchni, 20);
        $this->createSequence($u3, "Transport du malade", 1, 1, 4, $tahri, 10);
        $this->createSequence($u3, "Techniques d'hygiène pour la préparation au diagnostic", 1, 1, 5, $tahri, 14);
        $this->createSequence($u3, "Stérilisation", 2, 2, 6, $bihhadi, 20);
        $this->createSequence($u3, "Soins d'hygiène pour malade nécessitant une thérapeutique", 1, 1, 7, $tahri, 14);
        $this->createSequence($u3, "Soins d'hygiène pour malade nécessitant un pansement", 2, 2, 8, $tahri, 18);

        // Unite 4 - M. BIHHADI (1/3,2/3) + Mme TAHRI (3/3)
        $u4 = $this->createUnite('AS', 1, "Élément de sciences biologique", 6, 1, 4);
        $this->createSequence($u4, "Éléments d'Anatomie physiologie", 3, 3, 1, $bihhadi, 20);
        $this->createSequence($u4, "Éléments de microbiologie", 2, 2, 2, $bihhadi, 10);
        $this->createSequence($u4, "Éléments de diététique", 1, 1, 3, $tahri, 14);

        // Unite 5 - M. BIHHADI
        $u5 = $this->createUnite('AS', 1, "Éléments de pharmacologie", 2, 1, 5);
        $this->createSequence($u5, "Éléments de pharmacologie", 2, 2, 1, $bihhadi, 14);

        // Unite 6 - Mme TAHRI
        $u6 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en médecine et urgences", 4, 2, 6);
        $this->createSequence($u6, "Médecine", 2, 2, 1, $tahri, 20);
        $this->createSequence($u6, "Urgences", 2, 2, 2, $tahri, 20);

        // Unite 7 - Mme TAHRI
        $u7 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en chirurgie et réanimation", 4, 2, 7);
        $this->createSequence($u7, "Chirurgie", 2, 2, 1, $tahri, 20);
        $this->createSequence($u7, "Réanimation", 2, 2, 2, $tahri, 20);

        // Unite 8 - Mme SERGHOUCHNI
        $u8 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en Pédiatrie", 2, 2, 8);
        $this->createSequence($u8, "Soins en Pédiatrie", 2, 2, 1, $serghouchni, 20);

        // Unite 9 - Mme TAHRI
        $u9 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en psychiatrie", 2, 2, 9);
        $this->createSequence($u9, "Soins d'hygiène et confort en psychiatrie", 2, 2, 1, $tahri, 20);

        // Unite 10 - Mme SERGHOUCHNI
        $u10 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en Gynéco obstétrique", 2, 2, 10);
        $this->createSequence($u10, "Soins en Gynéco obstétrique", 2, 2, 1, $serghouchni, 20);

        // Unite 11 - Mme TAHRI
        $u11 = $this->createUnite('AS', 1, "Soins d'hygiène et confort en gériatrie et soins palliatifs", 2, 2, 11);
        $this->createSequence($u11, "Soins en gériatrie et soins palliatifs", 2, 2, 1, $tahri, 20);

        // Unite 12 - M. KAICHOUH
        $u12 = $this->createUnite('AS', 1, "Éléments de Sociologie-psychologie", 2, 2, 12);
        $this->createSequence($u12, "Élément de sociologie", 1, 1, 1, $kaichouh, 10);
        $this->createSequence($u12, "Éléments de psychologie", 1, 1, 2, $kaichouh, 10);

        // Unite 13 - M. EL HAMEL
        $u13 = $this->createUnite('AS', 1, "Droit-Législation", 1, 2, 13);
        $this->createSequence($u13, "Droit-Législation", 1, 1, 1, $elhameL, 14);

        // Unite 14 - Mme TAHRI
        $u14 = $this->createUnite('AS', 1, "Santé publique", 2, 2, 14);
        $this->createSequence($u14, "Système national de santé", 2, 2, 1, $tahri, 20);

        // Unite 15 - M. KAICHOUH (1/2) + Mme LOUKILI (2/2)
        $u15 = $this->createUnite('AS', 1, "Relation, communication", 3, 2, 15);
        $this->createSequence($u15, "Communication", 2, 2, 1, $kaichouh, 20);
        $this->createSequence($u15, "Technique de recherche d'emploi", 1, 1, 2, $loukili, 10);

        // Unite 16 - Mme LOUKILI
        $u16 = $this->createUnite('AS', 1, "Notions d'informatique", 2, 2, 16);
        $this->createSequence($u16, "Notions d'informatique", 2, 2, 1, $loukili, 20);

        // Unite 17 - Mme LOUKILI (Français) + M. BOULGHALEGH (Anglais)
        $u17 = $this->createUnite('AS', 1, "Langue", 4, 2, 17);
        $this->createSequence($u17, "Français", 2, 2, 1, $loukili, 40);
        $this->createSequence($u17, "Anglais", 2, 2, 2, $boulghalegh, 40);
    }

    // ═══════════════════════════════════════════════════
    // IA - Infirmier Auxiliaire (1ère année)
    // ═══════════════════════════════════════════════════

    protected function createIA1Data(): void
    {
        $rahmouni   = $this->formateurMap['19'];
        $serghouchni = $this->formateurMap['18'];
        $loukili    = $this->formateurMap['28'];
        $boulghalegh = $this->formateurMap['23'];
        $belataris  = $this->formateurMap['24'];
        $kaichouh   = $this->formateurMap['21'];

        // Unite 1 - Mme RAHMOUNI
        $u1 = $this->createUnite('IA', 1, "Préparation aux études et méthodologie de travail", 3, 1, 1);
        $this->createSequence($u1, "Préparation", 3, 3, 1, $rahmouni, 40);

        // Unite 2 - Mme RAHMOUNI
        $u2 = $this->createUnite('IA', 1, "L'infirmier auxiliaire et l'environnement professionnel", 2, 1, 2);
        $this->createSequence($u2, "Environnement professionnel", 2, 2, 1, $rahmouni, 20);

        // Unite 3 - M. KAICHOUH
        $u3 = $this->createUnite('IA', 1, "Communication", 3, 1, 3);
        $this->createSequence($u3, "Information éducation communication", 3, 3, 1, $kaichouh, 30);

        // Unite 4 - M. KAICHOUH
        $u4 = $this->createUnite('IA', 1, "Élément de psycho-sociologie", 4, 1, 4);
        $this->createSequence($u4, "Notions de sociologie", 2, 2, 1, $kaichouh, 20);
        $this->createSequence($u4, "Notions de psychologie", 2, 2, 2, $kaichouh, 20);

        // Unite 5 - Mme RAHMOUNI
        $u5 = $this->createUnite('IA', 1, "Anatomie", 3, 1, 5);
        $this->createSequence($u5, "Anatomie", 3, 3, 1, $rahmouni, 40);

        // Unite 6 - Mme SERGHOUCHNI
        $u6 = $this->createUnite('IA', 1, "Notions d'obstétrique", 1, 1, 6);
        $this->createSequence($u6, "Notions d'obstétrique", 1, 1, 1, $serghouchni, 10);

        // Unite 7 - Mme SERGHOUCHNI
        $u7 = $this->createUnite('IA', 1, "Notions de puériculture et diététique", 1, 1, 7);
        $this->createSequence($u7, "Puériculture", 1, 1, 1, $serghouchni, 15);

        // Unite 8 - M. BELATARIS
        $u8 = $this->createUnite('IA', 1, "Éléments scientifiques de base", 4, 1, 8);
        $this->createSequence($u8, "Notions de pharmacologie", 2, 2, 1, $belataris, 15);
        $this->createSequence($u8, "Microbio-parasito-entomologie", 2, 2, 2, $belataris, 10);

        // Unite 9 - Mme RAHMOUNI
        $u9 = $this->createUnite('IA', 1, "Nutrition et régimes alimentaires", 1, 1, 9);
        $this->createSequence($u9, "Nutrition", 1, 1, 1, $rahmouni, 15);

        // Unite 10 - Mme RAHMOUNI (1/2,2/2 parts) + M. BELATARIS (2/2 parts) + SERGHOUCHNI (4/5)
        $u10 = $this->createUnite('IA', 1, "Soins de base", 12, 1, 10);
        $this->createSequence($u10, "Hygiène et confort (1/5 Accueil)", 1, 1, 1, $rahmouni, 10);
        $this->createSequence($u10, "Hygiène et confort (2/5 Technique de literie)", 2, 2, 2, $rahmouni, 30);
        $this->createSequence($u10, "Hygiène et confort (3/5 Transport du malade)", 1, 1, 3, $rahmouni, 10);
        $this->createSequence($u10, "Hygiène et confort (4/5 Hygiène du nourrisson)", 2, 2, 4, $serghouchni, 20);
        $this->createSequence($u10, "Hygiène et confort (5/5 Hygiène de l'adulte)", 2, 2, 5, $rahmouni, 20);
        $this->createSequence($u10, "Techniques de base (1/5 Les constantes)", 2, 2, 6, $belataris, 25);
        $this->createSequence($u10, "Techniques de base (2/5 Administration médicaments)", 2, 2, 7, $belataris, 25);
        $this->createSequence($u10, "Techniques de base (3/5 Stérilisation)", 2, 2, 8, $belataris, 20);
        $this->createSequence($u10, "Techniques de base (4/5 Prélèvement)", 2, 2, 9, $rahmouni, 20);
        $this->createSequence($u10, "Techniques de base (5/5 Pansements et bandages)", 1, 1, 10, $rahmouni, 10);

        // Unite 11 - Mme RAHMOUNI
        $u11 = $this->createUnite('IA', 1, "Symptomatologie", 2, 2, 11);
        $this->createSequence($u11, "Symptomatologie", 2, 2, 1, $rahmouni, 20);

        // Unite 13 - Mme RAHMOUNI
        $u13 = $this->createUnite('IA', 1, "Hygiène individuelle", 2, 2, 13);
        $this->createSequence($u13, "Hygiène individuelle", 2, 2, 1, $rahmouni, 20);

        // Unite 15 - Mme RAHMOUNI
        $u15 = $this->createUnite('IA', 1, "Infrastructures sanitaires", 2, 2, 15);
        $this->createSequence($u15, "Infrastructures sanitaires", 2, 2, 1, $rahmouni, 20);

        // Unite 16 - Mme SERGHOUCHNI
        $u16 = $this->createUnite('IA', 1, "Santé de la mère et de l'enfant", 6, 2, 16);
        $this->createSequence($u16, "Santé mère enfant", 1, 1, 1, $serghouchni, 0);
        $this->createSequence($u16, "PLMD", 1, 1, 2, $serghouchni, 5);
        $this->createSequence($u16, "PLMC", 1, 1, 3, $serghouchni, 5);
        $this->createSequence($u16, "PNI", 1, 1, 4, $serghouchni, 5);
        $this->createSequence($u16, "PLIRA", 1, 1, 5, $serghouchni, 5);
        $this->createSequence($u16, "PSGA", 2, 2, 6, $serghouchni, 20);

        // Unite 20 - Mme LOUKILI (Français) + M. BOULGHALEGH (Anglais)
        $u20 = $this->createUnite('IA', 1, "Langues", 4, 2, 20);
        $this->createSequence($u20, "Français", 2, 2, 1, $loukili, 40);
        $this->createSequence($u20, "Anglais", 2, 2, 2, $boulghalegh, 40);

        // Unite 21 - Mme LOUKILI
        $u21 = $this->createUnite('IA', 1, "Notions d'informatique", 2, 2, 21);
        $this->createSequence($u21, "Informatique", 2, 2, 1, $loukili, 20);
    }

    // ═══════════════════════════════════════════════════
    // IA - Infirmier Auxiliaire (2ème année)
    // ═══════════════════════════════════════════════════

    protected function createIA2Data(): void
    {
        $rahmouni   = $this->formateurMap['19'];
        $serghouchni = $this->formateurMap['18'];
        $loukili    = $this->formateurMap['28'];
        $elhameL    = $this->formateurMap['22'];
        $hathout    = $this->formateurMap['25'];

        // Unite 11 - Mme RAHMOUNI
        $u11 = $this->createUnite('IA', 2, "Symptomatologie et Notions de pathologie", 1, 1, 11);
        $this->createSequence($u11, "Pathologies médicales", 0, 0, 1, $rahmouni, 20);
        $this->createSequence($u11, "Pathologies chirurgicales", 0, 0, 2, $rahmouni, 20);
        $this->createSequence($u11, "Pathologies pédiatriques", 1, 1, 3, $serghouchni, 20);

        // Unite 12 - M. HATHOUT
        $u12 = $this->createUnite('IA', 2, "Urgences et secours", 1, 1, 12);
        $this->createSequence($u12, "Urgences", 0, 0, 1, $hathout, 10);
        $this->createSequence($u12, "Secourisme", 1, 1, 2, $hathout, 10);

        // Unite 13 - Mme RAHMOUNI (4/6,5/6,6/6) + M. HATHOUT (2/6,3/6)
        $u13 = $this->createUnite('IA', 2, "L'Hygiène", 1, 2, 13);
        $this->createSequence($u13, "Hygiène de l'environnement", 0, 0, 1, $hathout, 10);
        $this->createSequence($u13, "Hygiène hospitalière", 0, 0, 2, $hathout, 10);
        $this->createSequence($u13, "Hygiène scolaire", 0, 0, 3, $rahmouni, 10);
        $this->createSequence($u13, "Hygiène du milieu", 0, 0, 4, $rahmouni, 10);
        $this->createSequence($u13, "Santé bucco-dentaire", 1, 1, 5, $rahmouni, 20);

        // Unite 14 - Mme RAHMOUNI
        $u14 = $this->createUnite('IA', 2, "Maladies transmissibles et prophylaxie", 1, 2, 14);
        $this->createSequence($u14, "Notions de prophylaxie", 1, 1, 1, $rahmouni, 10);
        $this->createSequence($u14, "Principales maladies transmissibles", 0, 0, 2, $rahmouni, 50);

        // System d'information - Mme RAHMOUNI
        $uSI = $this->createUnite('IA', 2, "Système d'information", 1, 2, 15);
        $this->createSequence($uSI, "Système d'information", 1, 1, 1, $rahmouni, 10);

        // Unite 18 - Mme RAHMOUNI
        $u18 = $this->createUnite('IA', 2, "Programmes de lutte", 0, 2, 18);
        $this->createSequence($u18, "Programmes de lutte", 0, 0, 1, $rahmouni, 25);

        // Demographie - Mme SERGHOUCHNI
        $uDemo = $this->createUnite('IA', 2, "Démographie et espacement des naissances", 0, 2, 19);
        $this->createSequence($uDemo, "Démographie et espacement des naissances", 0, 0, 1, $serghouchni, 30);

        // Unite 19 - M. EL HAMEL
        $u19 = $this->createUnite('IA', 2, "Éléments de droit et de gestion", 0, 2, 19);
        $this->createSequence($u19, "Notions de droit", 0, 0, 1, $elhameL, 20);
        $this->createSequence($u19, "Notions de gestion", 0, 0, 2, $elhameL, 10);

        // Unite 22 - Mme LOUKILI
        $u22 = $this->createUnite('IA', 2, "Notion en méthodologie de recherche", 0, 2, 22);
        $this->createSequence($u22, "Méthodologie de recherche", 0, 0, 1, $loukili, 10);
    }

    // ═══════════════════════════════════════════════════
    // IP - Infirmier Polyvalent (1ère année)
    // ═══════════════════════════════════════════════════

    protected function createIP1Data(): void
    {
        $mensoub    = $this->formateurMap['14'];
        $serghouchni = $this->formateurMap['18'];
        $loukili    = $this->formateurMap['28'];
        $kaichouh   = $this->formateurMap['21'];
        $boulghalegh = $this->formateurMap['23'];
        $elhameL    = $this->formateurMap['22'];
        $aissaoui   = $this->formateurMap['10'];

        // Unite 1 - M. MENSOUB (1/2) + M. KAICHOUH (2/2)
        $u1 = $this->createUnite('IP', 1, "Relation et communication", 5, 1, 1);
        $this->createSequence($u1, "Préparation aux études", 2, 2, 1, $mensoub, 40);
        $this->createSequence($u1, "Communication", 3, 3, 2, $kaichouh, 30);

        // Unite 2 - M. MENSOUB
        $u2 = $this->createUnite('IP', 1, "Introduction aux sciences infirmières", 4, 1, 2);
        $this->createSequence($u2, "Conceptualisation et planification", 2, 2, 1, $mensoub, 30);
        $this->createSequence($u2, "Éthique professionnelle", 2, 2, 2, $mensoub, 30);

        // Unite 3 - M. KAICHOUH
        $u3 = $this->createUnite('IP', 1, "Sciences Sociales et humaines", 6, 1, 3);
        $this->createSequence($u3, "Psychologie", 3, 3, 1, $kaichouh, 30);
        $this->createSequence($u3, "Sociologie", 3, 3, 2, $kaichouh, 30);

        // Unite 4 - M. EL HAMEL
        $u4 = $this->createUnite('IP', 1, "Éléments de droit", 3, 1, 4);
        $this->createSequence($u4, "Éléments de droit", 3, 3, 1, $elhameL, 50);

        // Unite 5 - M. MENSOUB
        $u5 = $this->createUnite('IP', 1, "Anatomie physiologie", 5, 1, 5);
        $this->createSequence($u5, "Anatomie physiologie", 5, 5, 1, $mensoub, 100);

        // Unite 6 - M. MENSOUB (1/2) + Mme AISSAOUI (2/2)
        $u6 = $this->createUnite('IP', 1, "Sciences biologiques", 7, 1, 6);
        $this->createSequence($u6, "Microbiologie et parasitologie", 3, 3, 1, $mensoub, 30);
        $this->createSequence($u6, "Pharmacologie", 4, 4, 2, $aissaoui, 50);

        // Unite 7 - M. MENSOUB (1/2) + Mme SERGHOUCHNI (2/2)
        $u7 = $this->createUnite('IP', 1, "Nutrition et Puériculture", 3, 1, 7);
        $this->createSequence($u7, "Nutrition", 1, 1, 1, $mensoub, 14);
        $this->createSequence($u7, "Puériculture", 2, 2, 2, $serghouchni, 20);

        // Unite 9 - M. MENSOUB
        $u9 = $this->createUnite('IP', 1, "Sémiologie et Terminologie", 4, 1, 9);
        $this->createSequence($u9, "Sémiologie", 2, 2, 1, $mensoub, 24);
        $this->createSequence($u9, "Terminologie", 2, 2, 2, $mensoub, 20);

        // Unite 10 - Mme AISSAOUI + M. MENSOUB (Accueil, Transport, Constantes, Examen lab)
        $u10 = $this->createUnite('IP', 1, "Soins infirmiers de base", 13, 1, 10);
        $this->createSequence($u10, "Hygiène et confort (1/5 Accueil)", 1, 1, 1, $mensoub, 10);
        $this->createSequence($u10, "Hygiène et confort (2/5 Transport)", 1, 1, 2, $mensoub, 10);
        $this->createSequence($u10, "Hygiène et confort (3/5 Technique literie)", 2, 2, 3, $aissaoui, 20);
        $this->createSequence($u10, "Hygiène et confort (4/5 Hygiène adulte)", 2, 2, 4, $aissaoui, 20);
        $this->createSequence($u10, "Hygiène et confort (5/5 Pansements)", 1, 1, 5, $aissaoui, 10);
        $this->createSequence($u10, "Techniques de base (1/5 Constantes)", 3, 3, 6, $mensoub, 30);
        $this->createSequence($u10, "Techniques de base (2/5 Administration médicaments)", 2, 2, 7, $aissaoui, 20);
        $this->createSequence($u10, "Techniques de base (3/5 Prélèvement)", 2, 2, 8, $aissaoui, 10);
        $this->createSequence($u10, "Techniques de base (4/5 Stérilisation)", 2, 2, 9, $aissaoui, 20);
        $this->createSequence($u10, "Techniques de base (5/5 Examen laboratoire)", 2, 2, 10, $mensoub, 10);

        // Unite 11 - M. MENSOUB
        $u11 = $this->createUnite('IP', 1, "Qualité et genre Soins", 4, 2, 11);
        $this->createSequence($u11, "Qualité des soins", 2, 2, 1, $mensoub, 20);
        $this->createSequence($u11, "Genre et soins", 2, 2, 2, $mensoub, 20);

        // Unite 12 - M. MENSOUB
        $u12 = $this->createUnite('IP', 1, "Médecine", 4, 2, 12);
        $this->createSequence($u12, "Pathologie et rôle infirmier", 2, 2, 1, $mensoub, 25);
        $this->createSequence($u12, "Soins infirmiers en médecine", 2, 2, 2, $mensoub, 15);

        // Unite 13 - M. MENSOUB
        $u13 = $this->createUnite('IP', 1, "Chirurgie", 4, 2, 13);
        $this->createSequence($u13, "Pathologie et rôle infirmier", 2, 2, 1, $mensoub, 25);
        $this->createSequence($u13, "Soins infirmiers en chirurgie", 2, 2, 2, $mensoub, 15);

        // Unite 16 - Mme SERGHOUCHNI
        $u16 = $this->createUnite('IP', 1, "Pédiatrie", 6, 2, 16);
        $this->createSequence($u16, "Pathologie pédiatrique", 3, 3, 1, $serghouchni, 30);
        $this->createSequence($u16, "Soins infirmiers en pédiatrie", 3, 3, 2, $serghouchni, 30);

        // Unite 27 - M. MENSOUB
        $u27 = $this->createUnite('IP', 1, "Système national de santé", 3, 2, 27);
        $this->createSequence($u27, "Système national de santé", 3, 3, 1, $mensoub, 50);

        // Unite 29 - M. MENSOUB (part) + Mme SERGHOUCHNI (part)
        $u29 = $this->createUnite('IP', 1, "Programmes d'activités sanitaires", 8, 2, 29);
        $this->createSequence($u29, "SMI", 1, 1, 1, $serghouchni, 4);
        $this->createSequence($u29, "PNI", 1, 1, 2, $mensoub, 10);
        $this->createSequence($u29, "PSGA", 1, 1, 3, $serghouchni, 20);
        $this->createSequence($u29, "PLMD", 1, 1, 4, $mensoub, 10);
        $this->createSequence($u29, "PLMPC", 1, 1, 5, $mensoub, 8);
        $this->createSequence($u29, "PSSU", 2, 2, 6, $mensoub, 20);

        // Unite 35 - Mme LOUKILI
        $u35 = $this->createUnite('IP', 1, "Notions d'informatique", 2, 2, 35);
        $this->createSequence($u35, "Informatique", 2, 2, 1, $loukili, 20);

        // Unite 36 - Mme LOUKILI (Français) + M. BOULGHALEGH (Anglais)
        $u36 = $this->createUnite('IP', 1, "Langue", 4, 2, 36);
        $this->createSequence($u36, "Français", 2, 2, 1, $loukili, 40);
        $this->createSequence($u36, "Anglais", 2, 2, 2, $boulghalegh, 40);
    }

    // ═══════════════════════════════════════════════════
    // IP - Infirmier Polyvalent (2ème année)
    // ═══════════════════════════════════════════════════

    protected function createIP2Data(): void
    {
        $ammari     = $this->formateurMap['02'];
        $mensoub    = $this->formateurMap['14'];
        $serghouchni = $this->formateurMap['18'];
        $loukili    = $this->formateurMap['28'];
        $saidi      = $this->formateurMap['05'];

        // Unite 8 - Mme AMMARI
        $u8 = $this->createUnite('IP', 2, "Régimes alimentaires", 2, 1, 8);
        $this->createSequence($u8, "Régimes alimentaires", 2, 2, 1, $ammari, 20);

        // Unite 12 - Mme AMMARI
        $u12 = $this->createUnite('IP', 2, "Médecine", 8, 1, 12);
        $this->createSequence($u12, "Pathologie médicale", 4, 4, 1, $ammari, 40);
        $this->createSequence($u12, "Soins infirmiers en médecine", 4, 4, 2, $ammari, 95);

        // Unite 13 - Mme AMMARI
        $u13 = $this->createUnite('IP', 2, "Chirurgie", 8, 1, 13);
        $this->createSequence($u13, "Pathologie chirurgicale", 4, 4, 1, $ammari, 40);
        $this->createSequence($u13, "Soins infirmiers en chirurgie", 4, 4, 2, $ammari, 95);

        // Unite 14 - Mme AMMARI (1/2) + Mme SAIDI (2/2)
        $u14 = $this->createUnite('IP', 2, "Réanimation", 4, 1, 14);
        $this->createSequence($u14, "Pathologie en réanimation", 1, 1, 1, $ammari, 10);
        $this->createSequence($u14, "Soins infirmiers en réanimation", 3, 3, 2, $saidi, 35);

        // Unite 15 - Mme SAIDI
        $u15 = $this->createUnite('IP', 2, "Soins infirmiers au bloc opératoire", 3, 1, 15);
        $this->createSequence($u15, "Bloc opératoire", 3, 3, 1, $saidi, 30);

        // Unite 16 - Mme SERGHOUCHNI
        $u16 = $this->createUnite('IP', 2, "Pédiatrie", 2, 1, 16);
        $this->createSequence($u16, "Soins infirmiers en pédiatrie", 2, 2, 1, $serghouchni, 20);

        // Unite 17 - Mme SAIDI
        $u17 = $this->createUnite('IP', 2, "Gériatrie", 3, 2, 17);
        $this->createSequence($u17, "Gériatrie", 1, 1, 1, $saidi, 10);
        $this->createSequence($u17, "Soins infirmiers aux personnes âgées", 2, 2, 2, $saidi, 10);

        // Unite 18 - Mme SERGHOUCHNI
        $u18 = $this->createUnite('IP', 2, "Obstétrique", 4, 2, 18);
        $this->createSequence($u18, "Obstétrique", 2, 2, 1, $serghouchni, 20);
        $this->createSequence($u18, "Soins infirmiers en obstétrique", 2, 2, 2, $serghouchni, 20);

        // Unite 19 - Mme AMMARI (1/2) + Mme SAIDI (2/2)
        $u19 = $this->createUnite('IP', 2, "Psychiatrie", 2, 2, 19);
        $this->createSequence($u19, "Pathologie psychiatrique", 1, 1, 1, $ammari, 10);
        $this->createSequence($u19, "Soins infirmiers en psychiatrie", 1, 1, 2, $saidi, 10);

        // Unite 20 - Mme AMMARI (1/2) + Mme SAIDI (2/2)
        $u20 = $this->createUnite('IP', 2, "Santé bucco-dentaire", 2, 2, 20);
        $this->createSequence($u20, "Pathologie bucco-dentaire", 1, 1, 1, $ammari, 6);
        $this->createSequence($u20, "Soins bucco-dentaire", 1, 1, 2, $saidi, 4);

        // Unite 26 - Mme AMMARI
        $u26 = $this->createUnite('IP', 2, "Épidémiologie et maladies transmissibles", 8, 2, 26);
        $this->createSequence($u26, "Épidémiologie", 3, 3, 1, $ammari, 30);
        $this->createSequence($u26, "Maladies transmissibles", 5, 5, 2, $ammari, 70);

        // Unite 29 - M. MENSOUB (part) + Mme SERGHOUCHNI (part)
        $u29 = $this->createUnite('IP', 2, "Programmes d'activités sanitaires", 12, 2, 29);
        $this->createSequence($u29, "Lutte anti paludéenne", 1, 1, 2, $mensoub, 10);
        $this->createSequence($u29, "Lutte contre la lèpre", 1, 1, 3, $mensoub, 10);
        $this->createSequence($u29, "Lutte anti tuberculeuse", 1, 1, 4, $mensoub, 16);
        $this->createSequence($u29, "Lutte infections nosocomiales", 1, 1, 6, $mensoub, 10);
        $this->createSequence($u29, "Lutte maladies hydriques", 1, 1, 7, $mensoub, 6);
        $this->createSequence($u29, "Lutte contre méningite", 1, 1, 8, $mensoub, 6);
        $this->createSequence($u29, "Lutte IRA", 1, 1, 9, $mensoub, 8);
        $this->createSequence($u29, "Hygiène du milieu", 1, 1, 11, $mensoub, 10);
        $this->createSequence($u29, "Lutte contre bilharziose", 1, 1, 1, $serghouchni, 10);
        $this->createSequence($u29, "Planification familiale", 3, 3, 10, $serghouchni, 40);
        $this->createSequence($u29, "Lutte MST et sida", 2, 2, 11, $serghouchni, 20);
        $this->createSequence($u29, "Santé reproductive", 1, 1, 12, $serghouchni, 6);

        // Unite 31 - Mme AMMARI
        $u31 = $this->createUnite('IP', 2, "Statistique sanitaire et démographie", 4, 2, 31);
        $this->createSequence($u31, "Statistique sanitaire", 2, 2, 1, $ammari, 14);
        $this->createSequence($u31, "Démographie", 2, 2, 2, $ammari, 14);

        // Unite 32 - Mme AMMARI
        $u32 = $this->createUnite('IP', 2, "Économie de santé", 3, 2, 32);
        $this->createSequence($u32, "Économie de santé", 3, 3, 1, $ammari, 30);

        // Unite 34 - Mme LOUKILI
        $u34 = $this->createUnite('IP', 2, "Méthodologie de recherche", 2, 2, 34);
        $this->createSequence($u34, "Méthodologie de recherche", 2, 2, 1, $loukili, 30);
    }

    // ═══════════════════════════════════════════════════
    // IP - Infirmier Polyvalent (3ème année)
    // ═══════════════════════════════════════════════════

    protected function createIP3Data(): void
    {
        $staili     = $this->formateurMap['27'];
        $serghouchni = $this->formateurMap['18'];
        $nejjari    = $this->formateurMap['16'];

        // Unite 21 - Mme SERGHOUCHNI
        $u21 = $this->createUnite('IP', 3, "Gynécologie", 3, 1, 21);
        $this->createSequence($u21, "Pathologie", 2, 2, 1, $serghouchni, 20);
        $this->createSequence($u21, "Soins infirmiers en gynécologie", 1, 1, 2, $serghouchni, 10);

        // Unite 22 - Dr STAILI (1/2) + Mme NEJJARI (2/2)
        $u22 = $this->createUnite('IP', 3, "Ophtalmologie", 2, 1, 22);
        $this->createSequence($u22, "Pathologie", 1, 1, 1, $staili, 10);
        $this->createSequence($u22, "Soins infirmiers en ophtalmologie", 1, 1, 2, $nejjari, 10);

        // Unite 23 - Dr STAILI (1/2) + Mme NEJJARI (2/2)
        $u23 = $this->createUnite('IP', 3, "Oto-rhino-laryngologie", 3, 1, 23);
        $this->createSequence($u23, "Pathologie", 1, 1, 1, $staili, 10);
        $this->createSequence($u23, "Soins infirmiers en ORL", 2, 2, 2, $nejjari, 20);

        // Unite 24 - Dr STAILI (1/2) + Mme NEJJARI (2/2)
        $u24 = $this->createUnite('IP', 3, "Dermatologie", 2, 1, 24);
        $this->createSequence($u24, "Pathologie", 1, 1, 1, $staili, 10);
        $this->createSequence($u24, "Soins infirmiers en dermatologie", 1, 1, 2, $nejjari, 10);

        // Unite 25 - Dr STAILI
        $u25 = $this->createUnite('IP', 3, "Urgences et secours", 3, 1, 25);
        $this->createSequence($u25, "Urgences et secours", 3, 3, 1, $staili, 30);

        // Unite 28 - Mme NEJJARI
        $u28 = $this->createUnite('IP', 3, "Diagnostic de santé", 3, 2, 28);
        $this->createSequence($u28, "Diagnostic et planification", 3, 3, 1, $nejjari, 20);

        // Unite 29 - Dr STAILI
        $u29 = $this->createUnite('IP', 3, "Programmes d'activités sanitaires", 4, 2, 29);
        $this->createSequence($u29, "Lutte contre leishmaniose", 1, 1, 1, $staili, 10);
        $this->createSequence($u29, "Hygiène mentale", 1, 1, 2, $staili, 5);
        $this->createSequence($u29, "Lutte maladies oculaires", 1, 1, 3, $staili, 5);
        $this->createSequence($u29, "Santé bucco-dentaire", 1, 1, 4, $staili, 5);

        // Unite 30 - Dr STAILI
        $u30 = $this->createUnite('IP', 3, "Santé au travail", 2, 2, 30);
        $this->createSequence($u30, "Santé au travail", 2, 2, 1, $staili, 25);

        // Unite 33 - Dr STAILI
        $u33 = $this->createUnite('IP', 3, "Principes de gestion", 3, 2, 33);
        $this->createSequence($u33, "Principes de gestion", 3, 3, 1, $staili, 30);
    }

    protected function printSummary(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Formateurs & Sequences Replaced Successfully\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "FORMATEURS: " . Formateur::count() . "\n\n";

        foreach ($this->formateurMap as $code => $formateur) {
            $totalHeures = DB::table('formateur_sequences')
                ->join('sequences', 'formateur_sequences.sequence_id', '=', 'sequences.id')
                ->where('formateur_sequences.formateur_id', $formateur->id)
                ->sum('formateur_sequences.masse_horaire');
            $name = $formateur->user->name;
            $seqCount = $formateur->sequences()->count();
            echo sprintf("  [%s] %-30s : %3d heures, %2d séquences\n", $code, $name, $totalHeures, $seqCount);
        }

        echo "\nUNITES: " . Unite::count() . "\n";
        echo "SEQUENCES: " . Sequence::count() . "\n";

        echo "\nBY FILIERE:\n";
        foreach (Filiere::with('niveaux')->get() as $f) {
            echo "  {$f->code} ({$f->nom}):\n";
            foreach ($f->niveaux as $n) {
                $uCount = Unite::where('filiere_id', $f->id)->where('numero_annee', $n->numero)->count();
                $sCount = Sequence::whereHas('unite', fn($q) => $q->where('filiere_id', $f->id)->where('numero_annee', $n->numero))->count();
                echo "    Niveau {$n->numero}: {$uCount} unites, {$sCount} sequences\n";
            }
        }

        echo "\n═══════════════════════════════════════════════════════\n";
    }
}
