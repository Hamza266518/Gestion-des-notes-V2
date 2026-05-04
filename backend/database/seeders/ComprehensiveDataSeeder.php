<?php

namespace Database\Seeders;

use App\Models\Filiere;
use App\Models\Niveau;
use App\Models\Unite;
use App\Models\Sequence;
use App\Models\AnneeAcademique;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ComprehensiveDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating comprehensive data from official document...');

        DB::transaction(function () {
            $annee = AnneeAcademique::where('is_current', true)->first();
            if (!$annee) {
                $this->command->error('No current academic year found.');
                return;
            }

            $asFiliere = Filiere::where('code', 'AS')->first();
            $iaFiliere = Filiere::where('code', 'IA')->first();
            $ipFiliere = Filiere::where('code', 'IP')->first();

            $asNiveau1 = Niveau::where('filiere_id', $asFiliere->id)->where('numero', 1)->first();
            $iaNiveau1 = Niveau::where('filiere_id', $iaFiliere->id)->where('numero', 1)->first();
            $iaNiveau2 = Niveau::where('filiere_id', $iaFiliere->id)->where('numero', 2)->first();
            $ipNiveau1 = Niveau::where('filiere_id', $ipFiliere->id)->where('numero', 1)->first();
            $ipNiveau2 = Niveau::where('filiere_id', $ipFiliere->id)->where('numero', 2)->first();
            $ipNiveau3 = Niveau::where('filiere_id', $ipFiliere->id)->where('numero', 3)->first();

            $this->createASUnites($asNiveau1);
            $this->createIA1Unites($iaNiveau1);
            $this->createIA2Unites($iaNiveau2);
            $this->createIP1Unites($ipNiveau1);
            $this->createIP2Unites($ipNiveau2);
            $this->createIP3Unites($ipNiveau3);
        });

        $this->command->info('Comprehensive data created successfully!');
        $this->printSummary();
    }

    private function createASUnites($niveau): void
    {
        $unitesData = [
            ['nom' => "Introduction aux soins d'hygiène et du confort du malade", 'coef' => 2, 'sem' => 1, 'ordre' => 1,
             'sequences' => [['nom' => "Introduction aux soins d'hygiène et du confort du malade", 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Hygiène : individuelle, envir, hospitalière', 'coef' => 2, 'sem' => 1, 'ordre' => 2,
             'sequences' => [
                ['nom' => 'Hygiène individuelle et collective', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Hygiène Hospitalière', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Surveillance de l\'état d\'hygiène et confort du malade', 'coef' => 10, 'sem' => 1, 'ordre' => 3,
             'sequences' => [
                ['nom' => 'Techniques de la literie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Hygiène de l\'adulte', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Hygiène du nourrisson', 'coef' => 2, 'ctrls' => 2, 'ordre' => 3],
                ['nom' => 'Transport du malade', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
                ['nom' => 'Techniques d\'hygiène pour la préparation au diagnostic', 'coef' => 1, 'ctrls' => 1, 'ordre' => 5],
                ['nom' => 'Stérilisation', 'coef' => 2, 'ctrls' => 2, 'ordre' => 6],
                ['nom' => 'Soins d\'hygiène pour malade nécessitant thérapeutique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 7],
                ['nom' => 'Soins d\'hygiène pour malade nécessitant pansement', 'coef' => 2, 'ctrls' => 2, 'ordre' => 8],
             ]],
            ['nom' => 'Élément de sciences biologique', 'coef' => 6, 'sem' => 1, 'ordre' => 4,
             'sequences' => [
                ['nom' => 'Éléments d\'Anatomie physiologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Éléments de microbiologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
                ['nom' => 'Éléments de diététique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
             ]],
            ['nom' => 'Éléments de pharmacologie', 'coef' => 2, 'sem' => 1, 'ordre' => 5,
             'sequences' => [['nom' => 'Éléments de pharmacologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Soins d\'hygiène et confort en médecine et urgences', 'coef' => 4, 'sem' => 2, 'ordre' => 6,
             'sequences' => [
                ['nom' => 'Médecine', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Urgences', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Soins d\'hygiène et confort en chirurgie et réanimation', 'coef' => 4, 'sem' => 2, 'ordre' => 7,
             'sequences' => [
                ['nom' => 'Chirurgie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Réanimation', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Soins d\'hygiène et confort en Pédiatrie', 'coef' => 2, 'sem' => 2, 'ordre' => 8,
             'sequences' => [['nom' => 'Soins d\'hygiène et confort en Pédiatrie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Soins d\'hygiène et confort en psychiatrie', 'coef' => 2, 'sem' => 2, 'ordre' => 9,
             'sequences' => [['nom' => 'Soins d\'hygiène et confort en psychiatrie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Soins d\'hygiène et confort en Gynéco obstétrique', 'coef' => 2, 'sem' => 2, 'ordre' => 10,
             'sequences' => [['nom' => 'Soins d\'hygiène et confort en Gynéco obstétrique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Soins d\'hygiène et confort en gériatrie et soins palliatifs', 'coef' => 2, 'sem' => 2, 'ordre' => 11,
             'sequences' => [['nom' => 'Soins d\'hygiène et confort en gériatrie et soins palliatifs', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Éléments de Sociologie-psychologie', 'coef' => 2, 'sem' => 2, 'ordre' => 12,
             'sequences' => [
                ['nom' => 'Élément de sociologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Éléments de psychologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Droit-Législation', 'coef' => 1, 'sem' => 2, 'ordre' => 13,
             'sequences' => [['nom' => 'Droit-Législation', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1]]],
            ['nom' => 'Santé publique', 'coef' => 2, 'sem' => 2, 'ordre' => 14,
             'sequences' => [['nom' => 'Système national de santé', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Relation, communication', 'coef' => 3, 'sem' => 2, 'ordre' => 15,
             'sequences' => [
                ['nom' => 'Communication', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Technique de recherche d\'emploi', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Notions d\'informatique', 'coef' => 2, 'sem' => 2, 'ordre' => 16,
             'sequences' => [['nom' => 'Notions d\'informatique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Langue', 'coef' => 4, 'sem' => 2, 'ordre' => 17,
             'sequences' => [
                ['nom' => 'Français', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Anglais', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createIA1Unites($niveau): void
    {
        $unitesData = [
            ['nom' => 'Préparation aux études et méthodologie de travail', 'coef' => 3, 'sem' => 1, 'ordre' => 1,
             'sequences' => [['nom' => 'Préparation aux études et méthodologie de travail', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'L\'infirmier auxiliaire et l\'environnement professionnel', 'coef' => 2, 'sem' => 1, 'ordre' => 2,
             'sequences' => [['nom' => 'L\'infirmier auxiliaire et l\'environnement professionnel', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Communication', 'coef' => 3, 'sem' => 1, 'ordre' => 3,
             'sequences' => [['nom' => 'Information éducation communication', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Élément de psycho-sociologie', 'coef' => 4, 'sem' => 1, 'ordre' => 4,
             'sequences' => [
                ['nom' => 'Notions de psychologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Notions de sociologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Anatomie', 'coef' => 3, 'sem' => 1, 'ordre' => 5,
             'sequences' => [['nom' => 'Anatomie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Notion d\'obstétrique', 'coef' => 1, 'sem' => 1, 'ordre' => 6,
             'sequences' => [['nom' => 'Notion d\'obstétrique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1]]],
            ['nom' => 'Notion de puériculture et de diététique', 'coef' => 1, 'sem' => 1, 'ordre' => 7,
             'sequences' => [['nom' => 'Notion de puériculture et de diététique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1]]],
            ['nom' => 'Éléments scientifiques de base', 'coef' => 4, 'sem' => 1, 'ordre' => 8,
             'sequences' => [
                ['nom' => 'Notions de pharmacologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Notions de microbio-Parasito-Entomologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Nutrition et régime alimentaire', 'coef' => 1, 'sem' => 1, 'ordre' => 9,
             'sequences' => [['nom' => 'Nutrition et régime alimentaire', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1]]],
            ['nom' => 'Soins de base', 'coef' => 12, 'sem' => 1, 'ordre' => 10,
             'sequences' => [
                ['nom' => 'Hygiène et confort du malade', 'coef' => 0, 'ctrls' => 0, 'ordre' => 1],
                ['nom' => 'Accueil', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Technique de la literie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 3],
                ['nom' => 'Transport du malade', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
                ['nom' => 'Hygiène du nourrisson', 'coef' => 2, 'ctrls' => 2, 'ordre' => 5],
                ['nom' => 'Hygiène de l\'adulte', 'coef' => 2, 'ctrls' => 2, 'ordre' => 6],
                ['nom' => 'Les constantes', 'coef' => 2, 'ctrls' => 2, 'ordre' => 7],
                ['nom' => 'Administration des médicaments', 'coef' => 2, 'ctrls' => 2, 'ordre' => 8],
                ['nom' => 'Stérilisation', 'coef' => 2, 'ctrls' => 2, 'ordre' => 9],
                ['nom' => 'Prélèvements', 'coef' => 2, 'ctrls' => 2, 'ordre' => 10],
                ['nom' => 'Pansements et bandages', 'coef' => 1, 'ctrls' => 1, 'ordre' => 11],
             ]],
            ['nom' => 'Symptomatologie et notion de pathologie', 'coef' => 2, 'sem' => 2, 'ordre' => 11,
             'sequences' => [['nom' => 'Symptomatologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Hygiènes', 'coef' => 2, 'sem' => 2, 'ordre' => 13,
             'sequences' => [['nom' => 'Hygiène individuelle', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Infrastructure sanitaires', 'coef' => 2, 'sem' => 2, 'ordre' => 15,
             'sequences' => [['nom' => 'Infrastructure sanitaires', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Santé de la mère et de l\'enfant', 'coef' => 6, 'sem' => 2, 'ordre' => 16,
             'sequences' => [
                ['nom' => 'Santé de la mère et de l\'enfant', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Programme PLMD', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Programme PLMC', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ['nom' => 'Programme PNI', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
                ['nom' => 'Programme PLIRA', 'coef' => 1, 'ctrls' => 1, 'ordre' => 5],
                ['nom' => 'Programme PSGA', 'coef' => 2, 'ctrls' => 2, 'ordre' => 6],
             ]],
            ['nom' => 'Langues', 'coef' => 4, 'sem' => 2, 'ordre' => 20,
             'sequences' => [
                ['nom' => 'Français', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Anglais', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Notions d\'informatique', 'coef' => 2, 'sem' => 2, 'ordre' => 21,
             'sequences' => [['nom' => 'Notions d\'informatique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createIA2Unites($niveau): void
    {
        $unitesData = [
            ['nom' => 'Symptomatologie et Notions de pathologie', 'coef' => 1, 'sem' => 1, 'ordre' => 11,
             'sequences' => [
                ['nom' => 'Les pathologies médicales', 'coef' => 0, 'ctrls' => 0, 'ordre' => 1],
                ['nom' => 'Les pathologies chirurgicales', 'coef' => 0, 'ctrls' => 0, 'ordre' => 2],
                ['nom' => 'Les pathologies pédiatriques', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
             ]],
            ['nom' => 'Urgences et secours', 'coef' => 1, 'sem' => 1, 'ordre' => 12,
             'sequences' => [
                ['nom' => 'Urgences', 'coef' => 0, 'ctrls' => 0, 'ordre' => 1],
                ['nom' => 'Secourisme', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'L\'Hygiène', 'coef' => 1, 'sem' => 2, 'ordre' => 13,
             'sequences' => [
                ['nom' => 'Hygiène de l\'environnement', 'coef' => 0, 'ctrls' => 0, 'ordre' => 1],
                ['nom' => 'Hygiène hospitalière', 'coef' => 0, 'ctrls' => 0, 'ordre' => 2],
                ['nom' => 'Hygiène scolaire', 'coef' => 0, 'ctrls' => 0, 'ordre' => 3],
                ['nom' => 'Hygiène du milieu', 'coef' => 0, 'ctrls' => 0, 'ordre' => 4],
                ['nom' => 'Santé bucco-dentaire', 'coef' => 1, 'ctrls' => 1, 'ordre' => 5],
             ]],
            ['nom' => 'Maladies transmissibles et prophylaxie', 'coef' => 1, 'sem' => 2, 'ordre' => 14,
             'sequences' => [['nom' => 'Notions de prophylaxie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1]]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createIP1Unites($niveau): void
    {
        $unitesData = [
            ['nom' => 'Relation et communication', 'coef' => 5, 'sem' => 1, 'ordre' => 1,
             'sequences' => [
                ['nom' => 'Préparation aux études et conférences de méthodes', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Communication', 'coef' => 3, 'ctrls' => 3, 'ordre' => 2],
             ]],
            ['nom' => 'Introduction aux sciences infirmières', 'coef' => 4, 'sem' => 1, 'ordre' => 2,
             'sequences' => [
                ['nom' => 'Conceptualisation et planification des soins infirmiers', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Éthique professionnelle et profession d\'infirmier', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Sciences Sociales et humaines', 'coef' => 6, 'sem' => 1, 'ordre' => 3,
             'sequences' => [
                ['nom' => 'Psychologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Sociologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 2],
             ]],
            ['nom' => 'Éléments de droit', 'coef' => 3, 'sem' => 1, 'ordre' => 4,
             'sequences' => [['nom' => 'Éléments de droit', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Anatomie physiologie', 'coef' => 5, 'sem' => 1, 'ordre' => 5,
             'sequences' => [['nom' => 'Anatomie physiologie', 'coef' => 5, 'ctrls' => 5, 'ordre' => 1]]],
            ['nom' => 'Sciences biologiques', 'coef' => 7, 'sem' => 1, 'ordre' => 6,
             'sequences' => [
                ['nom' => 'Microbiologie et parasitologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Pharmacologie', 'coef' => 4, 'ctrls' => 4, 'ordre' => 2],
             ]],
            ['nom' => 'Nutrition et Puériculture', 'coef' => 3, 'sem' => 1, 'ordre' => 7,
             'sequences' => [
                ['nom' => 'Nutrition', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Puériculture', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Sémiologie et Terminologie', 'coef' => 4, 'sem' => 1, 'ordre' => 9,
             'sequences' => [
                ['nom' => 'Sémiologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Terminologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Soins infirmiers de base', 'coef' => 13, 'sem' => 1, 'ordre' => 10,
             'sequences' => [
                ['nom' => 'Hygiène et confort', 'coef' => 0, 'ctrls' => 0, 'ordre' => 1],
                ['nom' => 'Accueil', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Transport de malade', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ['nom' => 'Technique de la literie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 4],
                ['nom' => 'Hygiènes de l\'adulte', 'coef' => 2, 'ctrls' => 2, 'ordre' => 5],
                ['nom' => 'Pansements et bandages', 'coef' => 1, 'ctrls' => 1, 'ordre' => 6],
                ['nom' => 'Les constantes', 'coef' => 3, 'ctrls' => 3, 'ordre' => 7],
                ['nom' => 'Administration des médicaments', 'coef' => 2, 'ctrls' => 2, 'ordre' => 8],
                ['nom' => 'Prélèvement', 'coef' => 2, 'ctrls' => 2, 'ordre' => 9],
                ['nom' => 'Stérilisation', 'coef' => 2, 'ctrls' => 2, 'ordre' => 10],
                ['nom' => 'Examen de laboratoire', 'coef' => 2, 'ctrls' => 2, 'ordre' => 11],
             ]],
            ['nom' => 'Qualité et genre Soins', 'coef' => 4, 'sem' => 2, 'ordre' => 11,
             'sequences' => [
                ['nom' => 'Qualité des soins', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Genre et soins', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Médecine (Pathologie et soins infirmiers)', 'coef' => 4, 'sem' => 2, 'ordre' => 12,
             'sequences' => [
                ['nom' => 'Pathologie et Rôle infirmier', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en Médecine', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Chirurgie', 'coef' => 4, 'sem' => 2, 'ordre' => 13,
             'sequences' => [
                ['nom' => 'Pathologie et Rôle infirmier', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en Chirurgie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Pédiatrie', 'coef' => 6, 'sem' => 2, 'ordre' => 16,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en Pédiatrie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 2],
             ]],
            ['nom' => 'Système national de santé', 'coef' => 3, 'sem' => 2, 'ordre' => 27,
             'sequences' => [['nom' => 'Système national de santé', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Programmes d\'activités sanitaires', 'coef' => 8, 'sem' => 2, 'ordre' => 29,
             'sequences' => [
                ['nom' => 'Santé maternelle et infantile (SMI)', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Programme national d\'immunisation (PNI)', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Programme PSGA', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ['nom' => 'Programme PLMD', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
                ['nom' => 'Programme PLMPC', 'coef' => 1, 'ctrls' => 1, 'ordre' => 5],
                ['nom' => 'Programme PSSU', 'coef' => 2, 'ctrls' => 2, 'ordre' => 6],
             ]],
            ['nom' => 'Notions d\'informatique', 'coef' => 2, 'sem' => 2, 'ordre' => 35,
             'sequences' => [['nom' => 'Notions d\'informatique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Langue', 'coef' => 4, 'sem' => 2, 'ordre' => 36,
             'sequences' => [
                ['nom' => 'Français', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Anglais', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createIP2Unites($niveau): void
    {
        $unitesData = [
            ['nom' => 'Régimes alimentaires', 'coef' => 2, 'sem' => 1, 'ordre' => 8,
             'sequences' => [['nom' => 'Régimes alimentaires', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Médecine', 'coef' => 8, 'sem' => 1, 'ordre' => 12,
             'sequences' => [
                ['nom' => 'Pathologie et Rôle infirmier', 'coef' => 4, 'ctrls' => 4, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en Médecine', 'coef' => 4, 'ctrls' => 4, 'ordre' => 2],
             ]],
            ['nom' => 'Chirurgie', 'coef' => 8, 'sem' => 1, 'ordre' => 13,
             'sequences' => [
                ['nom' => 'Pathologie et Rôle infirmier', 'coef' => 4, 'ctrls' => 4, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en Chirurgie', 'coef' => 4, 'ctrls' => 4, 'ordre' => 2],
             ]],
            ['nom' => 'Réanimation médico chirurgicale', 'coef' => 4, 'sem' => 1, 'ordre' => 14,
             'sequences' => [
                ['nom' => 'Pathologie et Rôle infirmier', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 3, 'ctrls' => 3, 'ordre' => 2],
             ]],
            ['nom' => 'Soins infirmiers au bloc opératoire', 'coef' => 3, 'sem' => 1, 'ordre' => 15,
             'sequences' => [['nom' => 'Soins infirmiers au bloc opératoire', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Pédiatrie', 'coef' => 2, 'sem' => 1, 'ordre' => 16,
             'sequences' => [['nom' => 'Soins infirmiers', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Gériatrie et soins infirmiers aux personnes âgées', 'coef' => 3, 'sem' => 2, 'ordre' => 17,
             'sequences' => [
                ['nom' => 'Gériatrie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers aux personnes âgées', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Obstétrique', 'coef' => 4, 'sem' => 2, 'ordre' => 18,
             'sequences' => [
                ['nom' => 'Obstétrique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Soins infirmiers en obstétrique', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Psychiatrie', 'coef' => 2, 'sem' => 2, 'ordre' => 19,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Santé bucco-dentaire', 'coef' => 2, 'sem' => 2, 'ordre' => 20,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'L\'hygiène bucco-dentaire', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Épidémiologie et maladies Transmissibles', 'coef' => 8, 'sem' => 2, 'ordre' => 26,
             'sequences' => [
                ['nom' => 'Épidémiologie', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1],
                ['nom' => 'Maladies transmissibles', 'coef' => 5, 'ctrls' => 5, 'ordre' => 2],
             ]],
            ['nom' => 'Programmes d\'activités sanitaires', 'coef' => 12, 'sem' => 2, 'ordre' => 29,
             'sequences' => [
                ['nom' => 'Programme lutte contre la bilharziose', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Programme lutte anti paludéenne', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Programme lutte contre la lèpre', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ['nom' => 'Programme lutte anti tuberculeuse', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
                ['nom' => 'Programme d\'hygiène du milieu', 'coef' => 1, 'ctrls' => 1, 'ordre' => 5],
                ['nom' => 'Programme lutte infections nosocomiales', 'coef' => 1, 'ctrls' => 1, 'ordre' => 6],
                ['nom' => 'Programme lutte maladies transmission hydrique', 'coef' => 1, 'ctrls' => 1, 'ordre' => 7],
                ['nom' => 'Programme lutte contre la méningite', 'coef' => 1, 'ctrls' => 1, 'ordre' => 8],
                ['nom' => 'Programme lutte infections respiratoires aigues', 'coef' => 1, 'ctrls' => 1, 'ordre' => 9],
                ['nom' => 'Programme de planification familiale', 'coef' => 3, 'ctrls' => 3, 'ordre' => 10],
                ['nom' => 'Programme lutte MST et sida', 'coef' => 2, 'ctrls' => 2, 'ordre' => 11],
                ['nom' => 'Concept de santé reproductive', 'coef' => 1, 'ctrls' => 1, 'ordre' => 12],
             ]],
            ['nom' => 'Statistique sanitaire et démographie', 'coef' => 4, 'sem' => 2, 'ordre' => 31,
             'sequences' => [
                ['nom' => 'Statistique sanitaire', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Démographie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Économie de santé', 'coef' => 3, 'sem' => 2, 'ordre' => 32,
             'sequences' => [['nom' => 'Économie de santé', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Méthodologie de recherche', 'coef' => 2, 'sem' => 2, 'ordre' => 34,
             'sequences' => [['nom' => 'Méthodologie de recherche', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createIP3Unites($niveau): void
    {
        $unitesData = [
            ['nom' => 'Gynécologie', 'coef' => 3, 'sem' => 1, 'ordre' => 21,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Ophtalmologie', 'coef' => 2, 'sem' => 1, 'ordre' => 22,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Oto-rhino-laryngologie', 'coef' => 3, 'sem' => 1, 'ordre' => 23,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 2, 'ctrls' => 2, 'ordre' => 2],
             ]],
            ['nom' => 'Dermatologie', 'coef' => 2, 'sem' => 1, 'ordre' => 24,
             'sequences' => [
                ['nom' => 'Pathologie', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'Soins infirmiers', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
             ]],
            ['nom' => 'Urgences et secours', 'coef' => 3, 'sem' => 1, 'ordre' => 25,
             'sequences' => [['nom' => 'Urgences et secours', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Diagnostic de santé d\'une population', 'coef' => 3, 'sem' => 2, 'ordre' => 28,
             'sequences' => [['nom' => 'Diagnostic de santé et planification', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
            ['nom' => 'Programmes d\'activités sanitaires', 'coef' => 4, 'sem' => 2, 'ordre' => 29,
             'sequences' => [
                ['nom' => 'Programme lutte contre leishmaniose', 'coef' => 1, 'ctrls' => 1, 'ordre' => 1],
                ['nom' => 'L\'hygiène mentale', 'coef' => 1, 'ctrls' => 1, 'ordre' => 2],
                ['nom' => 'Programme lutte maladies oculaires', 'coef' => 1, 'ctrls' => 1, 'ordre' => 3],
                ['nom' => 'Programme de santé bucco-dentaire', 'coef' => 1, 'ctrls' => 1, 'ordre' => 4],
             ]],
            ['nom' => 'Santé au travail', 'coef' => 2, 'sem' => 2, 'ordre' => 30,
             'sequences' => [['nom' => 'Santé au travail', 'coef' => 2, 'ctrls' => 2, 'ordre' => 1]]],
            ['nom' => 'Principes de gestion', 'coef' => 3, 'sem' => 2, 'ordre' => 33,
             'sequences' => [['nom' => 'Principes de gestion', 'coef' => 3, 'ctrls' => 3, 'ordre' => 1]]],
        ];

        $this->createUnitesAndSequences($niveau, $unitesData);
    }

    private function createUnitesAndSequences($niveau, $unitesData): void
    {
        foreach ($unitesData as $ud) {
            $unite = Unite::updateOrCreate(
                [
                    'nom' => $ud['nom'],
                    'filiere_id' => $niveau->filiere_id,
                ],
                [
                    'coefficient' => $ud['coef'],
                    'semestre' => $ud['sem'],
                    'numero_annee' => $niveau->numero,
                    'ordre' => $ud['ordre'],
                    'is_active' => true,
                ]
            );

            foreach ($ud['sequences'] as $sd) {
                if ($sd['coef'] > 0) {
                    Sequence::updateOrCreate(
                        [
                            'unite_id' => $unite->id,
                            'nom' => $sd['nom'],
                        ],
                        [
                            'coefficient' => $sd['coef'],
                            'nombre_controles' => $sd['ctrls'],
                            'ordre' => $sd['ordre'],
                            'is_active' => true,
                        ]
                    );
                }
            }
        }
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Comprehensive Data Seeded Successfully\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "RECORD COUNTS:\n";
        echo "  Unites:               " . Unite::count() . "\n";
        echo "  Sequences:            " . Sequence::count() . "\n";
        echo "  Controles (expected): " . Sequence::sum('nombre_controles') . "\n\n";

        echo "BY FILIERE:\n";
        $filieres = Filiere::with('niveaux')->get();
        foreach ($filieres as $f) {
            echo "  {$f->code} ({$f->nom}):\n";
            foreach ($f->niveaux as $n) {
                $uniteCount = Unite::where('filiere_id', $f->id)->where('numero_annee', $n->numero)->count();
                $sequenceCount = Sequence::whereHas('unite', function($q) use ($f, $n) {
                    $q->where('filiere_id', $f->id)->where('numero_annee', $n->numero);
                })->count();
                echo "    Niveau {$n->numero}: {$uniteCount} unites, {$sequenceCount} sequences\n";
            }
        }

        echo "\n═══════════════════════════════════════════════════════\n";
    }
}
