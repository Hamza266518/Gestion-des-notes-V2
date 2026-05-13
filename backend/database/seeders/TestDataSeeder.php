<?php

namespace Database\Seeders;

use App\Models\AnneeAcademique;
use App\Models\ActivityLog;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Examen;
use App\Models\Filiere;
use App\Models\Formateur;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\Sequence;
use App\Models\SemestrePublication;
use App\Models\Unite;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // ── Step 1: Annee Academique ──
            $annee = AnneeAcademique::firstOrCreate(
                ['label' => '2025/2026'],
                ['is_current' => true]
            );

            // ── Step 2: Filieres ──
            $filieres = [];
            $filieres['AS'] = Filiere::updateOrCreate(
                ['code' => 'AS'],
                ['nom' => 'Aide-Soignant', 'nom_ar' => 'مساعد معالج', 'section' => 'Qualification', 'type_formation' => 'Qualification', 'nombre_annees' => 1]
            );
            $filieres['IA'] = Filiere::updateOrCreate(
                ['code' => 'IA'],
                ['nom' => 'Infirmier Auxiliaire', 'nom_ar' => 'ممرض مساعد', 'section' => 'Technicien', 'type_formation' => 'Technicien', 'nombre_annees' => 2]
            );
            $filieres['IP'] = Filiere::updateOrCreate(
                ['code' => 'IP'],
                ['nom' => 'Infirmier Polyvalent', 'nom_ar' => 'ممرض متعدد الاختصاصات', 'section' => 'Technicien Specialise', 'type_formation' => 'Specialisation', 'nombre_annees' => 3]
            );

            // ── Step 2: Niveaux ──
            $niveaux = [];
            foreach (['AS' => 1, 'IA' => 2, 'IP' => 3] as $code => $count) {
                for ($i = 1; $i <= $count; $i++) {
                    $niveaux[$code . $i] = Niveau::firstOrCreate([
                        'filiere_id' => $filieres[$code]->id,
                        'numero' => $i,
                    ]);
                }
            }

            // ── Step 3: Groupes ──
            $groupes = [];

            // AS Niveau 1: Groupe A and B
            $groupes['AS_A'] = $this->createGroupe($niveaux['AS1'], $annee, 'Groupe A');
            $groupes['AS_B'] = $this->createGroupe($niveaux['AS1'], $annee, 'Groupe B');

            // IA Niveau 1: Groupe A
            $groupes['IA1_A'] = $this->createGroupe($niveaux['IA1'], $annee, 'Groupe A');
            // IA Niveau 2: Groupe A
            $groupes['IA2_A'] = $this->createGroupe($niveaux['IA2'], $annee, 'Groupe A');

            // IP Niveau 1, 2, 3: Groupe A
            $groupes['IP1_A'] = $this->createGroupe($niveaux['IP1'], $annee, 'Groupe A');
            $groupes['IP2_A'] = $this->createGroupe($niveaux['IP2'], $annee, 'Groupe A');
            $groupes['IP3_A'] = $this->createGroupe($niveaux['IP3'], $annee, 'Groupe A');

            // ── Step 4: Admin User ──
            $admin = User::firstOrCreate(
                ['email' => 'admin@ifp.ma'],
                [
                    'name' => 'Admin IFP',
                    'password' => Hash::make('admin123'),
                    'role' => 'admin',
                ]
            );

            // ── Step 5: Formateur Users ──
            $formateursData = [
                ['name' => 'Mohammed Alami', 'email' => 'm.alami@ifp.ma'],
                ['name' => 'Sara Idrissi', 'email' => 's.idrissi@ifp.ma'],
                ['name' => 'Youssef Benali', 'email' => 'y.benali@ifp.ma'],
                ['name' => 'Fatima Zahra Saidi', 'email' => 'fz.saidi@ifp.ma'],
                ['name' => 'Karim Tazi', 'email' => 'k.tazi@ifp.ma'],
            ];

            foreach ($formateursData as $fd) {
                $user = User::firstOrCreate(
                    ['email' => $fd['email']],
                    [
                        'name' => $fd['name'],
                        'password' => Hash::make('formateur123'),
                        'role' => 'formateur',
                    ]
                );
                Formateur::firstOrCreate(['user_id' => $user->id]);
            }

            // ── Step 6: Etudiants AS Groupe A (10 students) ──
            $asStudents = [
                ['nom_prenom' => 'FATIMA ZAHRA IDRISSI', 'cin' => 'AB123456', 'num' => '01 AS26'],
                ['nom_prenom' => 'SALIMA BENALI', 'cin' => 'CD789012', 'num' => '02 AS26'],
                ['nom_prenom' => 'HANAE TAZI', 'cin' => 'EF345678', 'num' => '03 AS26'],
                ['nom_prenom' => 'MERYEM ALAOUI', 'cin' => 'GH901234', 'num' => '04 AS26'],
                ['nom_prenom' => 'NOUR CHRAIBI', 'cin' => 'IJ567890', 'num' => '05 AS26'],
                ['nom_prenom' => 'ZINEB MANSOURI', 'cin' => 'KL234567', 'num' => '06 AS26'],
                ['nom_prenom' => 'ASMAE BERRADA', 'cin' => 'MN890123', 'num' => '07 AS26'],
                ['nom_prenom' => 'SOUKAINA KETTANI', 'cin' => 'OP456789', 'num' => '08 AS26'],
                ['nom_prenom' => 'HAJAR FILALI', 'cin' => 'QR012345', 'num' => '09 AS26'],
                ['nom_prenom' => 'IMANE TAHIRI', 'cin' => 'ST678901', 'num' => '10 AS26'],
            ];

            $asEtudiants = [];
            foreach ($asStudents as $s) {
                $email = strtolower($s['cin']) . '@ifp.ma';
                $password = $s['num'] . strtoupper(substr($s['cin'], 0, 2));
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $s['nom_prenom'],
                        'password' => Hash::make($password),
                        'role' => 'etudiant',
                    ]
                );
                $etudiant = Etudiant::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'groupe_id' => $groupes['AS_A']->id,
                        'annee_academique_id' => $annee->id,
                        'nom_prenom' => $s['nom_prenom'],
                        'cin' => $s['cin'],
                        'numero_inscription' => $s['num'],
                        'date_naissance' => '2000-01-01',
                        'status' => 'active',
                    ]
                );
                $asEtudiants[] = $etudiant;
            }

            // ── Step 7: Etudiants IP Niveau 3 Groupe A (5 students) ──
            $ipStudents = [
                ['nom_prenom' => 'OUMAIMA BOUTAYBI', 'cin' => 'UV234567', 'num' => '01 IP26'],
                ['nom_prenom' => 'KHADIJA AYADI', 'cin' => 'WX890123', 'num' => '02 IP26'],
                ['nom_prenom' => 'ASSIA ABBADI', 'cin' => 'YZ456789', 'num' => '03 IP26'],
                ['nom_prenom' => 'ILHAM ANAFNAF', 'cin' => 'AA012345', 'num' => '04 IP26'],
                ['nom_prenom' => 'OUMAYMA KADDAR', 'cin' => 'BB678901', 'num' => '05 IP26'],
            ];

            $ipEtudiants = [];
            foreach ($ipStudents as $s) {
                $email = strtolower($s['cin']) . '@ifp.ma';
                $password = $s['num'] . strtoupper(substr($s['cin'], 0, 2));
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $s['nom_prenom'],
                        'password' => Hash::make($password),
                        'role' => 'etudiant',
                    ]
                );
                $etudiant = Etudiant::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'groupe_id' => $groupes['IP3_A']->id,
                        'annee_academique_id' => $annee->id,
                        'nom_prenom' => $s['nom_prenom'],
                        'cin' => $s['cin'],
                        'numero_inscription' => $s['num'],
                        'date_naissance' => '2000-01-01',
                        'status' => 'active',
                    ]
                );
                $ipEtudiants[] = $etudiant;
            }

            // ── Step 8: Unites for AS filiere ──
            $unitesData = [
                ['nom' => "Introduction aux soins d'hygiène et du confort du malade", 'coef' => 4, 'sem' => 1, 'ordre' => 1],
                ['nom' => 'Hygiène individuelle et collective', 'coef' => 4, 'sem' => 1, 'ordre' => 2],
                ['nom' => 'Eléments de sciences biologiques', 'coef' => 4, 'sem' => 1, 'ordre' => 3],
                ['nom' => 'Éléments de pharmacologie', 'coef' => 2, 'sem' => 1, 'ordre' => 4],
                ['nom' => "Soins d'hygiène en médecine et urgences", 'coef' => 4, 'sem' => 2, 'ordre' => 5],
                ['nom' => 'Langue', 'coef' => 2, 'sem' => 2, 'ordre' => 6],
                ['nom' => "Notions d'informatique", 'coef' => 2, 'sem' => 2, 'ordre' => 7],
            ];

            $unites = [];
            foreach ($unitesData as $ud) {
                $unites[$ud['ordre']] = Unite::firstOrCreate(
                    ['nom' => $ud['nom']],
                    [
                        'filiere_id' => $filieres['AS']->id,
                        'coefficient' => $ud['coef'],
                        'semestre' => $ud['sem'],
                        'numero_annee' => 1,
                        'ordre' => $ud['ordre'],
                        'is_active' => true,
                    ]
                );
            }

            // ── Step 9: Sequences ──
            $sequencesData = [
                // Unite 1
                1 => [
                    ['nom' => "Introduction aux soins d'hygiène", 'coef' => 4, 'ctrls' => 2, 'ordre' => 1],
                ],
                // Unite 2
                2 => [
                    ['nom' => 'Hygiène individuelle', 'coef' => 2, 'ctrls' => 1, 'ordre' => 1],
                    ['nom' => 'Hygiène Hospitalière', 'coef' => 2, 'ctrls' => 1, 'ordre' => 2],
                ],
                // Unite 3
                3 => [
                    ['nom' => "Eléments d'Anatomie physiologie", 'coef' => 2, 'ctrls' => 3, 'ordre' => 1],
                    ['nom' => 'Eléments de microbiologie', 'coef' => 1, 'ctrls' => 2, 'ordre' => 2],
                    ['nom' => 'Eléments de diététique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ],
                // Unite 4
                4 => [
                    ['nom' => 'Pharmacologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ],
                // Unite 5
                5 => [
                    ['nom' => 'Médecine', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                    ['nom' => 'Urgences', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
                ],
                // Unite 6
                6 => [
                    ['nom' => 'Français', 'coef' => 1, 'ctrls' => 2, 'ordre' => 1],
                    ['nom' => 'Anglais', 'coef' => 1, 'ctrls' => 2, 'ordre' => 2],
                ],
                // Unite 7
                7 => [
                    ['nom' => 'Informatique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ],
            ];

            $allSequences = [];
            $allControles = [];

            foreach ($sequencesData as $uniteOrdre => $seqs) {
                foreach ($seqs as $sd) {
                    $seq = Sequence::firstOrCreate([
                        'unite_id' => $unites[$uniteOrdre]->id,
                        'nom' => $sd['nom'],
                    ], [
                        'coefficient' => $sd['coef'],
                        'nombre_controles' => $sd['ctrls'],
                        'ordre' => $sd['ordre'],
                        'is_active' => true,
                    ]);
                    $allSequences[] = $seq;

                    // ── Step 10: Controles ──
                    for ($c = 1; $c <= $sd['ctrls']; $c++) {
                        $ctrl = Controle::firstOrCreate([
                            'sequence_id' => $seq->id,
                            'numero' => $c,
                        ], [
                            'note_max' => 20,
                            'date' => null,
                        ]);
                        $allControles[] = $ctrl;
                    }
                }
            }

            // ── Step 11: Notes for AS Groupe A ──
            $studentProfiles = [
                0  => ['min' => 14, 'max' => 18], // FATIMA ZAHRA - strong
                1  => ['min' => 11, 'max' => 14], // SALIMA - average
                8  => ['min' => 12, 'max' => 16], // HAJAR
                9  => ['min' => 8,  'max' => 12], // IMANE - weak
            ];

            $defaultProfile = ['min' => 12, 'max' => 16];

            $confirmedAt = '2026-01-15 10:00:00';

            foreach ($allControles as $ctrl) {
                foreach ($asEtudiants as $idx => $etudiant) {
                    $profile = $studentProfiles[$idx] ?? $defaultProfile;
                    $score = rand($profile['min'] * 4, $profile['max'] * 4) / 4; // quarter points

                    Note::firstOrCreate([
                        'etudiant_id' => $etudiant->id,
                        'controle_id' => $ctrl->id,
                    ], [
                        'valeur' => $score,
                        'is_confirmed' => true,
                        'confirmed_at' => $confirmedAt,
                        'chemin_image' => null,
                    ]);
                }
            }

            // ── Step 12: Examens for AS Groupe A ──
            // Theorique for all 7 unites
            $uniteTheoriqueConfig = [
                1 => ['bloc' => 1, 'sem' => 1],
                2 => ['bloc' => 1, 'sem' => 1],
                3 => ['bloc' => 1, 'sem' => 1],
                4 => ['bloc' => 1, 'sem' => 1],
                5 => ['bloc' => 2, 'sem' => 2],
                6 => ['bloc' => 2, 'sem' => 2],
                7 => ['bloc' => 2, 'sem' => 2],
            ];

            foreach ($uniteTheoriqueConfig as $ordre => $config) {
                foreach ($asEtudiants as $idx => $etudiant) {
                    $profile = $studentProfiles[$idx] ?? $defaultProfile;
                    $score = rand($profile['min'] * 4, $profile['max'] * 4) / 4;

                    Examen::firstOrCreate([
                        'etudiant_id' => $etudiant->id,
                        'unite_id' => $unites[$ordre]->id,
                        'bloc' => $config['bloc'],
                        'type' => 'theorique',
                        'semestre' => $config['sem'],
                        'annee_academique_id' => $annee->id,
                    ], [
                        'valeur' => $score,
                    ]);
                }
            }

            // Pratique for unites 1, 2, 5
            $unitePratiqueConfig = [
                1 => ['bloc' => 1, 'sem' => 1],
                2 => ['bloc' => 1, 'sem' => 1],
                5 => ['bloc' => 2, 'sem' => 2],
            ];

            foreach ($unitePratiqueConfig as $ordre => $config) {
                foreach ($asEtudiants as $idx => $etudiant) {
                    $profile = $studentProfiles[$idx] ?? $defaultProfile;
                    // Pratique scores slightly higher: between 12 and 19
                    $pMin = max(12, $profile['min']);
                    $pMax = min(19, $profile['max'] + 1);
                    $score = rand($pMin * 4, $pMax * 4) / 4;

                    Examen::firstOrCreate([
                        'etudiant_id' => $etudiant->id,
                        'unite_id' => $unites[$ordre]->id,
                        'bloc' => $config['bloc'],
                        'type' => 'pratique',
                        'semestre' => $config['sem'],
                        'annee_academique_id' => $annee->id,
                    ], [
                        'valeur' => $score,
                    ]);
                }
            }

            // ── Step 13: Publication ──
            SemestrePublication::firstOrCreate([
                'groupe_id' => $groupes['AS_A']->id,
                'annee_academique_id' => $annee->id,
                'type' => 'notes_s1',
            ], [
                'is_published' => true,
                'published_at' => '2026-02-15 09:00:00',
            ]);

            // ── Step 14: Activity Logs ──
            $activities = [
                [
                    'action_type' => 'create',
                    'description' => 'Admin IFP a importé 10 stagiaires dans AS Groupe A',
                    'model_type' => 'App\Models\Etudiant',
                    'created_at' => '2026-01-10 10:00:00',
                ],
                [
                    'action_type' => 'create',
                    'description' => "Admin IFP a créé l'unité Hygiène individuelle et collective",
                    'model_type' => 'App\Models\Unite',
                    'created_at' => '2026-01-11 11:00:00',
                ],
                [
                    'action_type' => 'update',
                    'description' => 'Admin IFP a modifié la note de IMANE TAHIRI de 7 à 9 — Hygiène individuelle C1',
                    'model_type' => 'App\Models\Note',
                    'created_at' => '2026-01-15 14:00:00',
                ],
                [
                    'action_type' => 'create',
                    'description' => 'Admin IFP a créé le formateur Mohammed Alami',
                    'model_type' => 'App\Models\Formateur',
                    'created_at' => '2026-01-12 09:00:00',
                ],
                [
                    'action_type' => 'publish',
                    'description' => 'Admin IFP a publié le bulletin Semestre 1 pour AS Groupe A',
                    'model_type' => 'App\Models\SemestrePublication',
                    'created_at' => '2026-02-15 09:00:00',
                ],
                [
                    'action_type' => 'update',
                    'description' => 'Admin IFP a modifié la note de SALIMA BENALI de 10 à 12 — Pharmacologie C1',
                    'model_type' => 'App\Models\Note',
                    'created_at' => '2026-01-20 15:00:00',
                ],
                [
                    'action_type' => 'create',
                    'description' => 'Admin IFP a saisi les notes d\'examen théorique Bloc 1 pour AS Groupe A',
                    'model_type' => 'App\Models\Examen',
                    'created_at' => '2026-02-01 10:00:00',
                ],
                [
                    'action_type' => 'delete',
                    'description' => 'Admin IFP a supprimé le stagiaire TEST STAGIAIRE',
                    'model_type' => 'App\Models\Etudiant',
                    'created_at' => '2026-01-08 08:00:00',
                ],
                [
                    'action_type' => 'create',
                    'description' => 'Admin IFP a créé l\'année académique 2025/2026',
                    'model_type' => 'App\Models\AnneeAcademique',
                    'created_at' => '2026-01-05 08:00:00',
                ],
                [
                    'action_type' => 'update',
                    'description' => 'Admin IFP a modifié le groupe AS Groupe A',
                    'model_type' => 'App\Models\Groupe',
                    'created_at' => '2026-01-06 09:00:00',
                ],
            ];

            foreach ($activities as $act) {
                ActivityLog::firstOrCreate([
                    'description' => $act['description'],
                ], [
                    'admin_id' => $admin->id,
                    'admin_name' => $admin->name,
                    'action_type' => $act['action_type'],
                    'model_type' => $act['model_type'],
                    'model_id' => null,
                    'created_at' => $act['created_at'],
                    'updated_at' => $act['created_at'],
                ]);
            }
        });

        // ── Print Summary ──
        $this->printSummary();
    }

    private function createGroupe(Niveau $niveau, AnneeAcademique $annee, string $nom): Groupe
    {
        return Groupe::firstOrCreate([
            'niveau_id' => $niveau->id,
            'annee_academique_id' => $annee->id,
            'nom' => $nom,
        ], [
            'promotion' => '2025/2026',
        ]);
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  IFP - Test Data Seeded Successfully\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "RECORD COUNTS:\n";
        echo "  Users:                " . User::count() . "\n";
        echo "  Annees Academiques:   " . AnneeAcademique::count() . "\n";
        echo "  Filieres:             " . Filiere::count() . "\n";
        echo "  Niveaux:              " . Niveau::count() . "\n";
        echo "  Groupes:              " . Groupe::count() . "\n";
        echo "  Etudiants:            " . Etudiant::count() . "\n";
        echo "  Formateurs:           " . Formateur::count() . "\n";
        echo "  Unites:               " . Unite::count() . "\n";
        echo "  Sequences:            " . Sequence::count() . "\n";
        echo "  Controles:            " . Controle::count() . "\n";
        echo "  Notes:                " . Note::count() . "\n";
        echo "  Examens:              " . Examen::count() . "\n";
        echo "  Publications:         " . SemestrePublication::count() . "\n";
        echo "  Activity Logs:        " . ActivityLog::count() . "\n";

        echo "\n═══════════════════════════════════════════════════════\n";
        echo "  TEST CREDENTIALS\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "ADMIN:\n";
        echo "  email: admin@ifp.ma\n";
        echo "  password: admin123\n\n";

        echo "FORMATEURS (all use same password):\n";
        echo "  email: m.alami@ifp.ma      password: formateur123\n";
        echo "  email: s.idrissi@ifp.ma    password: formateur123\n";
        echo "  email: y.benali@ifp.ma     password: formateur123\n";
        echo "  email: fz.saidi@ifp.ma     password: formateur123\n";
        echo "  email: k.tazi@ifp.ma       password: formateur123\n\n";

        echo "ETUDIANTS (AS Groupe A):\n";
        echo "  email: ab123456@ifp.ma     password: 01 AS26AB  (FATIMA ZAHRA - strong)\n";
        echo "  email: cd789012@ifp.ma     password: 02 AS26CD  (SALIMA - average)\n";
        echo "  email: ef345678@ifp.ma     password: 03 AS26EF  (HANAE)\n";
        echo "  email: gh901234@ifp.ma     password: 04 AS26GH  (MERYEM)\n";
        echo "  email: ij567890@ifp.ma     password: 05 AS26IJ  (NOUR)\n";
        echo "  email: kl234567@ifp.ma     password: 06 AS26KL  (ZINEB)\n";
        echo "  email: mn890123@ifp.ma     password: 07 AS26MN  (ASMAE)\n";
        echo "  email: op456789@ifp.ma     password: 08 AS26OP  (SOUKAINA)\n";
        echo "  email: qr012345@ifp.ma     password: 09 AS26QR  (HAJAR)\n";
        echo "  email: st678901@ifp.ma     password: 10 AS26ST  (IMANE - weakest)\n\n";

        echo "ETUDIANTS (IP Niveau 3 Groupe A):\n";
        echo "  email: uv234567@ifp.ma     password: 01 IP26UV\n";
        echo "  email: wx890123@ifp.ma     password: 02 IP26WX\n";
        echo "  email: yz456789@ifp.ma     password: 03 IP26YZ\n";
        echo "  email: aa012345@ifp.ma     password: 04 IP26AA\n";
        echo "  email: bb678901@ifp.ma     password: 05 IP26BB\n\n";

        echo "═══════════════════════════════════════════════════════\n";
    }
}
