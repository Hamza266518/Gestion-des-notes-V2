<?php

namespace App\Imports;

use App\Models\Formateur;
use App\Models\Unite;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class FormateurUnitesImport implements ToCollection, WithHeadingRow
{
    protected $formateur_id;
    public $assigned = 0;
    public $errors   = [];

    public function __construct(int $formateur_id)
    {
        $this->formateur_id = $formateur_id;
    }

    public function collection(Collection $rows)
    {
        $formateur = Formateur::findOrFail($this->formateur_id);

        foreach ($rows as $row) {
            if (empty($row['nom_unite'])) continue;

            $unite = Unite::whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($row['nom_unite']) . '%'])->first();

            if (!$unite) {
                $this->errors[] = 'Unité non trouvée: ' . $row['nom_unite'];
                continue;
            }

            $formateur->unites()->syncWithoutDetaching([$unite->id]);
            $this->assigned++;
        }
    }
}