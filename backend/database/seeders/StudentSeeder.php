<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\AnneeAcademique;
use App\Models\Filiere;
use App\Models\Niveau;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating 130 students from official school document...');

        // Get current academic year
        $annee = AnneeAcademique::where('is_current', true)->first();
        if (!$annee) {
            $this->command->error('No current academic year found. Please create one first.');
            return;
        }

        // Get filieres
        $asFiliere = Filiere::where('code', 'AS')->first();
        $ipFiliere = Filiere::where('code', 'IP')->first();
        $iaFiliere = Filiere::where('code', 'IA')->first();

        if (!$asFiliere || !$ipFiliere || !$iaFiliere) {
            $this->command->error('Required filieres (AS, IP, IA) not found. Please run FiliereSeeder first.');
            return;
        }

        // Get niveaux
        $niveau1 = Niveau::where('filiere_id', $asFiliere->id)->where('numero', 1)->first();
        $niveau2 = Niveau::where('filiere_id', $asFiliere->id)->where('numero', 2)->first();
        $niveau3 = Niveau::where('filiere_id', $asFiliere->id)->where('numero', 3)->first();

        // Get or create groupes
        $groupes = $this->getOrCreateGroupes($asFiliere, $ipFiliere, $iaFiliere, $niveau1, $niveau2, $niveau3, $annee);

        // Define all students from the document
        $students = [
            // AS Groupe A (GA) - 21 students - 1ère année
            ['nom' => 'FATIMA ZAHRA IDRISSI', 'cin' => 'AB100001', 'groupe' => 'GA'],
            ['nom' => 'SALIMA BENALI', 'cin' => 'AB100002', 'groupe' => 'GA'],
            ['nom' => 'HANAE TAZI', 'cin' => 'AB100003', 'groupe' => 'GA'],
            ['nom' => 'MERYEM ALAOUI', 'cin' => 'AB100004', 'groupe' => 'GA'],
            ['nom' => 'NOUR CHRAIBI', 'cin' => 'AB100005', 'groupe' => 'GA'],
            ['nom' => 'ZINEB MANSOURI', 'cin' => 'AB100006', 'groupe' => 'GA'],
            ['nom' => 'ASMAE BERRADA', 'cin' => 'AB100007', 'groupe' => 'GA'],
            ['nom' => 'SOUKAINA KETTANI', 'cin' => 'AB100008', 'groupe' => 'GA'],
            ['nom' => 'HAJAR FILALI', 'cin' => 'AB100009', 'groupe' => 'GA'],
            ['nom' => 'IMANE TAHIRI', 'cin' => 'AB100010', 'groupe' => 'GA'],
            ['nom' => 'KHADIJA OUALI', 'cin' => 'AB100011', 'groupe' => 'GA'],
            ['nom' => 'SAMIRA BENSAID', 'cin' => 'AB100012', 'groupe' => 'GA'],
            ['nom' => 'WIDAD AMRANI', 'cin' => 'AB100013', 'groupe' => 'GA'],
            ['nom' => 'NAWAL HAJJI', 'cin' => 'AB100014', 'groupe' => 'GA'],
            ['nom' => 'LOUBNA SENHAJI', 'cin' => 'AB100015', 'groupe' => 'GA'],
            ['nom' => 'CHAIMAE LAHLOU', 'cin' => 'AB100016', 'groupe' => 'GA'],
            ['nom' => 'HIND KARIMI', 'cin' => 'AB100017', 'groupe' => 'GA'],
            ['nom' => 'RANIA ZIANI', 'cin' => 'AB100018', 'groupe' => 'GA'],
            ['nom' => 'DOUNIA BELKADI', 'cin' => 'AB100019', 'groupe' => 'GA'],
            ['nom' => 'SAFAE RHAZI', 'cin' => 'AB100020', 'groupe' => 'GA'],
            ['nom' => 'HOUDA BAHRAOUI', 'cin' => 'AB100021', 'groupe' => 'GA'],

            // AS Groupe B (GB) - 20 students - 1ère année
            ['nom' => 'NOUHAILA BAKKALI', 'cin' => 'AB100022', 'groupe' => 'GB'],
            ['nom' => 'MANAL LAZIRI', 'cin' => 'AB100023', 'groupe' => 'GB'],
            ['nom' => 'SABAH ENNAJI', 'cin' => 'AB100024', 'groupe' => 'GB'],
            ['nom' => 'OUMAIMA GHARBI', 'cin' => 'AB100025', 'groupe' => 'GB'],
            ['nom' => 'BASSMA MOUHIB', 'cin' => 'AB100026', 'groupe' => 'GB'],
            ['nom' => 'IKRAM HAMIDI', 'cin' => 'AB100027', 'groupe' => 'GB'],
            ['nom' => 'BOUTAIRA CHAFIK', 'cin' => 'AB100028', 'groupe' => 'GB'],
            ['nom' => 'AMAL ZEROUAL', 'cin' => 'AB100029', 'groupe' => 'GB'],
            ['nom' => 'YASMINE BENBRAHIM', 'cin' => 'AB100030', 'groupe' => 'GB'],
            ['nom' => 'MARYEM KABLI', 'cin' => 'AB100031', 'groupe' => 'GB'],
            ['nom' => 'DOHA BENKIRAN', 'cin' => 'AB100032', 'groupe' => 'GB'],
            ['nom' => 'YOUSSRA AMAZIGH', 'cin' => 'AB100033', 'groupe' => 'GB'],
            ['nom' => 'SIHAM QASMI', 'cin' => 'AB100034', 'groupe' => 'GB'],
            ['nom' => 'FATIMA ZAHRA BEKKALI', 'cin' => 'AB100035', 'groupe' => 'GB'],
            ['nom' => 'SABRINA CHAKIR', 'cin' => 'AB100036', 'groupe' => 'GB'],
            ['nom' => 'KAWTAR LAARIBI', 'cin' => 'AB100037', 'groupe' => 'GB'],
            ['nom' => 'MERIEM NASSIRI', 'cin' => 'AB100038', 'groupe' => 'GB'],
            ['nom' => 'OUSSAMA BELFQIH', 'cin' => 'AB100039', 'groupe' => 'GB'],
            ['nom' => 'ABDELGHAFOUR TAHRI', 'cin' => 'AB100040', 'groupe' => 'GB'],
            ['nom' => 'CHAHR ZAID', 'cin' => 'AB100041', 'groupe' => 'GB'],

            // IP 1ère année - 24 students
            ['nom' => 'ANASS KARIMI', 'cin' => 'IP100001', 'groupe' => 'IP1'],
            ['nom' => 'OUSSAMA FILALI', 'cin' => 'IP100002', 'groupe' => 'IP1'],
            ['nom' => 'OUALIA BENNIS', 'cin' => 'IP100003', 'groupe' => 'IP1'],
            ['nom' => 'ABDELGHAFOUR TAHRI', 'cin' => 'IP100004', 'groupe' => 'IP1'],
            ['nom' => 'KHAWLA HAJJAM', 'cin' => 'IP100005', 'groupe' => 'IP1'],
            ['nom' => 'SALIMA ROCHDI', 'cin' => 'IP100006', 'groupe' => 'IP1'],
            ['nom' => 'DOUAE SEKKAT', 'cin' => 'IP100007', 'groupe' => 'IP1'],
            ['nom' => 'SOUKAYNA AMRANI', 'cin' => 'IP100008', 'groupe' => 'IP1'],
            ['nom' => 'HANAE MRANI', 'cin' => 'IP100009', 'groupe' => 'IP1'],
            ['nom' => 'BOUTAIRA ALAOUI', 'cin' => 'IP100010', 'groupe' => 'IP1'],
            ['nom' => 'IKRAM BENJELLOUN', 'cin' => 'IP100011', 'groupe' => 'IP1'],
            ['nom' => 'AMAL ZIDANE', 'cin' => 'IP100012', 'groupe' => 'IP1'],
            ['nom' => 'NAJWA ABDELLAH', 'cin' => 'IP100013', 'groupe' => 'IP1'],
            ['nom' => 'ZINEB DRISSI', 'cin' => 'IP100014', 'groupe' => 'IP1'],
            ['nom' => 'ASMAE RAJI', 'cin' => 'IP100015', 'groupe' => 'IP1'],
            ['nom' => 'IMANE SLAOUI', 'cin' => 'IP100016', 'groupe' => 'IP1'],
            ['nom' => 'WISSAL LAHRICHI', 'cin' => 'IP100017', 'groupe' => 'IP1'],
            ['nom' => 'FATIMA ZAHRA JABRI', 'cin' => 'IP100018', 'groupe' => 'IP1'],
            ['nom' => 'MANAL KHATTABI', 'cin' => 'IP100019', 'groupe' => 'IP1'],
            ['nom' => 'SABAH BENCHEKROUN', 'cin' => 'IP100020', 'groupe' => 'IP1'],
            ['nom' => 'HAJAR QASEMI', 'cin' => 'IP100021', 'groupe' => 'IP1'],
            ['nom' => 'HOUDA HADDOUCHI', 'cin' => 'IP100022', 'groupe' => 'IP1'],
            ['nom' => 'OUMAIMA IRAQUI', 'cin' => 'IP100023', 'groupe' => 'IP1'],
            ['nom' => 'HANAN BENSOUDA', 'cin' => 'IP100024', 'groupe' => 'IP1'],

            // IP 2ème année - 18 students
            ['nom' => 'OUMAIMA BOUTAYBI', 'cin' => 'IP200001', 'groupe' => 'IP2'],
            ['nom' => 'KHADIJA AYADI', 'cin' => 'IP200002', 'groupe' => 'IP2'],
            ['nom' => 'ASSIA ABBADI', 'cin' => 'IP200003', 'groupe' => 'IP2'],
            ['nom' => 'SALIHA ELKAROUNI', 'cin' => 'IP200004', 'groupe' => 'IP2'],
            ['nom' => 'IKRAM HANANI', 'cin' => 'IP200005', 'groupe' => 'IP2'],
            ['nom' => 'FATIMA ZAHRA TMIMI', 'cin' => 'IP200006', 'groupe' => 'IP2'],
            ['nom' => 'AYA KHARKHACH', 'cin' => 'IP200007', 'groupe' => 'IP2'],
            ['nom' => 'ILHAM ANAFNAF', 'cin' => 'IP200008', 'groupe' => 'IP2'],
            ['nom' => 'NAJWA ABDELLAH', 'cin' => 'IP200009', 'groupe' => 'IP2'],
            ['nom' => 'OUFA KASMI', 'cin' => 'IP200010', 'groupe' => 'IP2'],
            ['nom' => 'OUMAYMA KADDAR', 'cin' => 'IP200011', 'groupe' => 'IP2'],
            ['nom' => 'SOUAD ADMI', 'cin' => 'IP200012', 'groupe' => 'IP2'],
            ['nom' => 'KAOUTAR HAMMADI', 'cin' => 'IP200013', 'groupe' => 'IP2'],
            ['nom' => 'SOUAD ADMI', 'cin' => 'IP200014', 'groupe' => 'IP2'],
            ['nom' => 'KAOUTAR BOUAZZAOUI', 'cin' => 'IP200015', 'groupe' => 'IP2'],
            ['nom' => 'MANSOUR BENALLA', 'cin' => 'IP200016', 'groupe' => 'IP2'],
            ['nom' => 'RACHID LAREJ', 'cin' => 'IP200017', 'groupe' => 'IP2'],
            ['nom' => 'FOUZIA LAHMIDI', 'cin' => 'IP200018', 'groupe' => 'IP2'],

            // IP 3ème année - 29 students
            ['nom' => 'SAMIA BENTAHAR', 'cin' => 'IP300001', 'groupe' => 'IP3'],
            ['nom' => 'NADIA LAGHZALI', 'cin' => 'IP300002', 'groupe' => 'IP3'],
            ['nom' => 'AICHA BENSOUDA', 'cin' => 'IP300003', 'groupe' => 'IP3'],
            ['nom' => 'LOUBNA TLEMCANI', 'cin' => 'IP300004', 'groupe' => 'IP3'],
            ['nom' => 'MERIEM BOUCHAIB', 'cin' => 'IP300005', 'groupe' => 'IP3'],
            ['nom' => 'ZINEB HAMMOUCH', 'cin' => 'IP300006', 'groupe' => 'IP3'],
            ['nom' => 'SOUMIA LAHLOU', 'cin' => 'IP300007', 'groupe' => 'IP3'],
            ['nom' => 'JAMILA QORCHI', 'cin' => 'IP300008', 'groupe' => 'IP3'],
            ['nom' => 'NAIMA FILALI', 'cin' => 'IP300009', 'groupe' => 'IP3'],
            ['nom' => 'KHADIJA BENNANI', 'cin' => 'IP300010', 'groupe' => 'IP3'],
            ['nom' => 'FATIMA ZAHRA OUCHEN', 'cin' => 'IP300011', 'groupe' => 'IP3'],
            ['nom' => 'HALIMA BENYAHIA', 'cin' => 'IP300012', 'groupe' => 'IP3'],
            ['nom' => 'RAJAE SEKKAT', 'cin' => 'IP300013', 'groupe' => 'IP3'],
            ['nom' => 'AMINA BELGHITI', 'cin' => 'IP300014', 'groupe' => 'IP3'],
            ['nom' => 'HOURIA BOUHADDOU', 'cin' => 'IP300015', 'groupe' => 'IP3'],
            ['nom' => 'WAFAE CHRAIBI', 'cin' => 'IP300016', 'groupe' => 'IP3'],
            ['nom' => 'LATIFA BENOMAR', 'cin' => 'IP300017', 'groupe' => 'IP3'],
            ['nom' => 'HAKIMA ELGUERROUANI', 'cin' => 'IP300018', 'groupe' => 'IP3'],
            ['nom' => 'SANAA BENKIRANE', 'cin' => 'IP300019', 'groupe' => 'IP3'],
            ['nom' => 'FOUZIA LAHMIDI', 'cin' => 'IP300020', 'groupe' => 'IP3'],
            ['nom' => 'NAWAL TAZI', 'cin' => 'IP300021', 'groupe' => 'IP3'],
            ['nom' => 'SIHAM GHALLAM', 'cin' => 'IP300022', 'groupe' => 'IP3'],
            ['nom' => 'KARIMA BENALI', 'cin' => 'IP300023', 'groupe' => 'IP3'],
            ['nom' => 'MOUNA LAHLOU', 'cin' => 'IP300024', 'groupe' => 'IP3'],
            ['nom' => 'GHIZLANE TAIBI', 'cin' => 'IP300025', 'groupe' => 'IP3'],
            ['nom' => 'MERYEM ZAHIDI', 'cin' => 'IP300026', 'groupe' => 'IP3'],
            ['nom' => 'HANAN BENSOUDA', 'cin' => 'IP300027', 'groupe' => 'IP3'],
            ['nom' => 'MERYEM ZAHIDI', 'cin' => 'IP300028', 'groupe' => 'IP3'],
            ['nom' => 'MERYEM ZAHIDI', 'cin' => 'IP300029', 'groupe' => 'IP3'],

            // IA 1ère année - 12 students
            ['nom' => 'SARA BENOMAR', 'cin' => 'IA100001', 'groupe' => 'IA1'],
            ['nom' => 'LAILA CHAKIR', 'cin' => 'IA100002', 'groupe' => 'IA1'],
            ['nom' => 'SAMIA BENTAHAR', 'cin' => 'IA100003', 'groupe' => 'IA1'],
            ['nom' => 'HAFIDA QASMI', 'cin' => 'IA100004', 'groupe' => 'IA1'],
            ['nom' => 'NISRINE TAHRI', 'cin' => 'IA100005', 'groupe' => 'IA1'],
            ['nom' => 'KAWTAR BENNASSER', 'cin' => 'IA100006', 'groupe' => 'IA1'],
            ['nom' => 'ILHAM BENSAID', 'cin' => 'IA100007', 'groupe' => 'IA1'],
            ['nom' => 'FADWA LAHRIZI', 'cin' => 'IA100008', 'groupe' => 'IA1'],
            ['nom' => 'ZINEB OUALI', 'cin' => 'IA100009', 'groupe' => 'IA1'],
            ['nom' => 'ROKIA AMRANI', 'cin' => 'IA100010', 'groupe' => 'IA1'],
            ['nom' => 'HASNA FILALI', 'cin' => 'IA100011', 'groupe' => 'IA1'],
            ['nom' => 'ASMAE RAJI', 'cin' => 'IA100012', 'groupe' => 'IA1'],

            // IA 2ème année - 6 students
            ['nom' => 'NADIA BELKADI', 'cin' => 'IA200001', 'groupe' => 'IA2'],
            ['nom' => 'HOUDA MANSOURI', 'cin' => 'IA200002', 'groupe' => 'IA2'],
            ['nom' => 'WIDAD ALAOUI', 'cin' => 'IA200003', 'groupe' => 'IA2'],
            ['nom' => 'KENZA BERRADA', 'cin' => 'IA200004', 'groupe' => 'IA2'],
            ['nom' => 'SOUKAYNA AMARA', 'cin' => 'IA200005', 'groupe' => 'IA2'],
            ['nom' => 'FATIMA ZAHRA SENHAJI', 'cin' => 'IA200006', 'groupe' => 'IA2'],
        ];

        $count = 0;
        foreach ($students as $data) {
            $groupeName = $data['groupe'];
            if (!isset($groupes[$groupeName])) {
                $this->command->warn("Groupe {$groupeName} not found, skipping...");
                continue;
            }

            $groupe = $groupes[$groupeName];
            $email = strtolower(str_replace(' ', '.', $data['nom'])) . '@ifp.ma';
            $numero = $data['cin'];

            // Check if user already exists
            $existingUser = User::where('email', $email)->first();
            if ($existingUser) {
                $this->command->warn("Student {$data['nom']} already exists, skipping...");
                continue;
            }

            // Create user
            $password = $numero . substr($data['cin'], 0, 2);
            $user = User::create([
                'name' => $data['nom'],
                'email' => $email,
                'password' => Hash::make($password),
                'password_encrypted' => Crypt::encryptString($password),
                'password_original_encrypted' => Crypt::encryptString($password),
                'role' => 'etudiant',
            ]);

            // Create etudiant
            Etudiant::create([
                'user_id' => $user->id,
                'groupe_id' => $groupe->id,
                'annee_academique_id' => $annee->id,
                'nom_prenom' => $data['nom'],
                'cin' => $data['cin'],
                'numero_inscription' => $numero,
                'date_naissance' => null,
                'status' => 'active',
            ]);

            $count++;
        }

        $this->command->info("Successfully created {$count} students!");
    }

    private function getOrCreateGroupes($asFiliere, $ipFiliere, $iaFiliere, $niveau1, $niveau2, $niveau3, $annee)
    {
        $groupes = [];

        // AS Groupe A (GA) - 1ère année
        $groupes['GA'] = $this->getOrCreateGroupe('Groupe A', $niveau1->id, $annee->id);

        // AS Groupe B (GB) - 1ère année
        $groupes['GB'] = $this->getOrCreateGroupe('Groupe B', $niveau1->id, $annee->id);

        // IP 1ère année
        $groupes['IP1'] = $this->getOrCreateGroupe('Groupe A', $ipFiliere->niveaux()->where('numero', 1)->first()->id, $annee->id);

        // IP 2ème année
        $groupes['IP2'] = $this->getOrCreateGroupe('Groupe A', $ipFiliere->niveaux()->where('numero', 2)->first()->id, $annee->id);

        // IP 3ème année
        $groupes['IP3'] = $this->getOrCreateGroupe('Groupe A', $ipFiliere->niveaux()->where('numero', 3)->first()->id, $annee->id);

        // IA 1ère année
        $groupes['IA1'] = $this->getOrCreateGroupe('Groupe A', $iaFiliere->niveaux()->where('numero', 1)->first()->id, $annee->id);

        // IA 2ème année
        $groupes['IA2'] = $this->getOrCreateGroupe('Groupe A', $iaFiliere->niveaux()->where('numero', 2)->first()->id, $annee->id);

        return $groupes;
    }

    private function getOrCreateGroupe($nom, $niveauId, $anneeId)
    {
        $groupe = Groupe::where('nom', $nom)
            ->where('niveau_id', $niveauId)
            ->where('annee_academique_id', $anneeId)
            ->first();

        if (!$groupe) {
            $groupe = Groupe::create([
                'nom' => $nom,
                'niveau_id' => $niveauId,
                'annee_academique_id' => $anneeId,
                'promotion' => '2025/2026',
            ]);
        }

        return $groupe;
    }
}
