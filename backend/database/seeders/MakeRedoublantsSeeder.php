<?php

namespace Database\Seeders;

use App\Models\Etudiant;
use App\Models\Note;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MakeRedoublantsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $students = [
                // AS: 3 redoublants
                ['id' => 257, 'min' => 2, 'max' => 6],  // DOUNIA SERGHINI
                ['id' => 258, 'min' => 3, 'max' => 7],  // SARA DOUKKALI
                ['id' => 259, 'min' => 1, 'max' => 5],  // AYOUB SEBTI
                // IA: 3 redoublants
                ['id' => 298, 'min' => 2, 'max' => 7],  // RACHID ZAGHLOUL
                ['id' => 299, 'min' => 3, 'max' => 6],  // KARIM BERADA
                ['id' => 300, 'min' => 1, 'max' => 5],  // IMRANE OUAZZANI
                // IP: 3 redoublants
                ['id' => 316, 'min' => 4, 'max' => 8],  // SALMA SEBTI
                ['id' => 317, 'min' => 2, 'max' => 6],  // FATIMA ZAHRA FILALI
                ['id' => 318, 'min' => 3, 'max' => 7],  // IHSSANE MESTOURI
            ];

            foreach ($students as $s) {
                $etudiant = Etudiant::find($s['id']);
                if (!$etudiant) {
                    $this->command->warn("Étudiant id={$s['id']} introuvable");
                    continue;
                }

                $count = 0;
                foreach ($etudiant->notes as $note) {
                    $score = rand($s['min'] * 4, $s['max'] * 4) / 4;
                    $note->update(['valeur' => $score]);
                    $count++;
                }

                $annee = \App\Models\AnneeAcademique::where('is_current', true)->first();
                $moy = app(\App\Services\MoyenneService::class)->moyenneGenerale($etudiant->id, null, $annee?->id);
                $this->command->info("  {$etudiant->nom_prenom}: {$count} notes → moyenne {$moy}");
            }
        });

        $this->command->info('✅ 9 redoublants créés (3 par filière)');
    }
}
