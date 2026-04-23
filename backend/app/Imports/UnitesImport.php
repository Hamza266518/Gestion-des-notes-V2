<?php

namespace App\Imports;

use App\Models\Filiere;
use App\Models\Unite;
use App\Models\Sequence;
use App\Models\Controle;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class UnitesImport implements ToCollection, WithHeadingRow
{
    protected $filiere_id;
    public $created = 0;
    public $errors  = [];

    public function __construct(int $filiere_id)
    {
        $this->filiere_id = $filiere_id;
    }

    public function collection(Collection $rows)
    {
        $unitesCache = [];

        foreach ($rows as $row) {
            if (empty($row['unite_nom']) || empty($row['sequence_nom'])) continue;

            $uniteKey = $row['unite_nom'] . '_' . $row['unite_annee'] . '_' . $row['unite_semestre'];

            // create unite only once
            if (!isset($unitesCache[$uniteKey])) {
                $unite = Unite::firstOrCreate(
                    [
                        'filiere_id'   => $this->filiere_id,
                        'nom'          => $row['unite_nom'],
                        'numero_annee' => $row['unite_annee'],
                        'semestre'     => $row['unite_semestre'],
                    ],
                    [
                        'coefficient' => $row['unite_coefficient'],
                        'ordre'       => Unite::where('filiere_id', $this->filiere_id)->count() + 1,
                        'is_active'   => true,
                    ]
                );
                $unitesCache[$uniteKey] = $unite;
                $this->created++;
            }

            $unite = $unitesCache[$uniteKey];

            // create sequence
            $sequence = Sequence::firstOrCreate(
                [
                    'unite_id' => $unite->id,
                    'nom'      => $row['sequence_nom'],
                ],
                [
                    'coefficient'      => $row['sequence_coefficient'],
                    'nombre_controles' => $row['sequence_controles'],
                    'ordre'            => Sequence::where('unite_id', $unite->id)->count() + 1,
                    'is_active'        => true,
                ]
            );

            // create controles automatically
            for ($i = 1; $i <= $row['sequence_controles']; $i++) {
                Controle::firstOrCreate([
                    'sequence_id' => $sequence->id,
                    'numero'      => $i,
                ], [
                    'note_max' => 20,
                ]);
            }
        }
    }
}