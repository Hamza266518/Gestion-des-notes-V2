<?php

namespace Database\Seeders;

use App\Models\Formateur;
use App\Models\Sequence;
use App\Models\Unite;
use App\Models\Filiere;
use App\Models\Niveau;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FormateurSequenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Assigning formateurs to sequences...');

        // Clear existing assignments
        DB::table('formateur_sequences')->truncate();

        // Get formateurs
        $formateurs = Formateur::with('user')->get();
        if ($formateurs->count() == 0) {
            $this->command->error('No formateurs found. Please run TestDataSeeder first.');
            return;
        }

        $assignments = 0;

        // Assign formateurs to sequences based on specialization
        // Formateur 1 (Mohammed Alami) - AS sequences
        $formateur1 = $formateurs->where('user.name', 'Mohammed Alami')->first();
        if ($formateur1) {
            $asUnites = Unite::whereHas('filiere', function($q) {
                $q->where('code', 'AS');
            })->pluck('id');
            $asSequences = Sequence::whereIn('unite_id', $asUnites)->get();
            foreach ($asSequences as $seq) {
                DB::table('formateur_sequences')->insert([
                    'formateur_id' => $formateur1->id,
                    'sequence_id' => $seq->id,
                    'masse_horaire' => rand(20, 40),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $assignments++;
            }
            $this->command->info("Assigned Mohammed Alami to {$asSequences->count()} AS sequences");
        }

        // Formateur 2 (Sara Idrissi) - IA sequences (year 1)
        $formateur2 = $formateurs->where('user.name', 'Sara Idrissi')->first();
        if ($formateur2) {
            $iaNiveau1 = Niveau::whereHas('filiere', function($q) {
                $q->where('code', 'IA');
            })->where('numero', 1)->first();
            
            if ($iaNiveau1) {
                $iaUnites = Unite::where('filiere_id', $iaNiveau1->filiere_id)
                    ->where('numero_annee', 1)
                    ->pluck('id');
                $iaSequences = Sequence::whereIn('unite_id', $iaUnites)->get();
                foreach ($iaSequences as $seq) {
                    DB::table('formateur_sequences')->insert([
                        'formateur_id' => $formateur2->id,
                        'sequence_id' => $seq->id,
                        'masse_horaire' => rand(15, 35),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $assignments++;
                }
                $this->command->info("Assigned Sara Idrissi to {$iaSequences->count()} IA 1ère année sequences");
            }
        }

        // Formateur 3 (Youssef Benali) - IP sequences (year 1)
        $formateur3 = $formateurs->where('user.name', 'Youssef Benali')->first();
        if ($formateur3) {
            $ipNiveau1 = Niveau::whereHas('filiere', function($q) {
                $q->where('code', 'IP');
            })->where('numero', 1)->first();
            
            if ($ipNiveau1) {
                $ipUnites = Unite::where('filiere_id', $ipNiveau1->filiere_id)
                    ->where('numero_annee', 1)
                    ->pluck('id');
                $ipSequences = Sequence::whereIn('unite_id', $ipUnites)->get();
                foreach ($ipSequences as $seq) {
                    DB::table('formateur_sequences')->insert([
                        'formateur_id' => $formateur3->id,
                        'sequence_id' => $seq->id,
                        'masse_horaire' => rand(20, 45),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $assignments++;
                }
                $this->command->info("Assigned Youssef Benali to {$ipSequences->count()} IP 1ère année sequences");
            }
        }

        // Formateur 4 (Fatima Zahra Saidi) - IP sequences (year 2 & 3)
        $formateur4 = $formateurs->where('user.name', 'Fatima Zahra Saidi')->first();
        if ($formateur4) {
            $ipNiveaux = Niveau::whereHas('filiere', function($q) {
                $q->where('code', 'IP');
            })->whereIn('numero', [2, 3])->get();
            
            $ipUnites = Unite::whereIn('numero_annee', [2, 3])
                ->whereHas('filiere', function($q) {
                    $q->where('code', 'IP');
                })
                ->pluck('id');
            $ipSequences = Sequence::whereIn('unite_id', $ipUnites)->get();
            foreach ($ipSequences as $seq) {
                DB::table('formateur_sequences')->insert([
                    'formateur_id' => $formateur4->id,
                    'sequence_id' => $seq->id,
                    'masse_horaire' => rand(25, 50),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $assignments++;
            }
            $this->command->info("Assigned Fatima Zahra Saidi to {$ipSequences->count()} IP 2ème & 3ème année sequences");
        }

        // Formateur 5 (Karim Tazi) - IA sequences (year 2) + some shared sequences
        $formateur5 = $formateurs->where('user.name', 'Karim Tazi')->first();
        if ($formateur5) {
            $iaNiveau2 = Niveau::whereHas('filiere', function($q) {
                $q->where('code', 'IA');
            })->where('numero', 2)->first();
            
            if ($iaNiveau2) {
                $iaUnites = Unite::where('filiere_id', $iaNiveau2->filiere_id)
                    ->where('numero_annee', 2)
                    ->pluck('id');
                $iaSequences = Sequence::whereIn('unite_id', $iaUnites)->get();
                foreach ($iaSequences as $seq) {
                    DB::table('formateur_sequences')->insert([
                        'formateur_id' => $formateur5->id,
                        'sequence_id' => $seq->id,
                        'masse_horaire' => rand(15, 30),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $assignments++;
                }
                $this->command->info("Assigned Karim Tazi to {$iaSequences->count()} IA 2ème année sequences");
            }
        }

        $this->command->info("Total formateur-sequence assignments: {$assignments}");
        $this->printSummary();
    }

    private function printSummary(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Formateur-Sequence Assignments Complete\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        $formateurs = Formateur::with('user')->get();
        foreach ($formateurs as $f) {
            $count = DB::table('formateur_sequences')->where('formateur_id', $f->id)->count();
            echo "  {$f->user->name}: {$count} sequences assigned\n";
        }

        echo "\n═══════════════════════════════════════════════════════\n";
    }
}
