<?php

namespace App\Imports;

use App\Models\Etudiant;
use App\Models\User;
use App\Services\NumeroInscriptionService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class EtudiantsImport implements ToCollection, WithHeadingRow
{
    protected $groupe_id;
    protected $annee_academique_id;
    protected $filiere_code;
    protected $numeroService;
    public $created = 0;
    public $updated = 0;
    public $errors  = [];

    public function __construct(int $groupe_id, int $annee_academique_id, string $filiere_code)
    {
        $this->groupe_id           = $groupe_id;
        $this->annee_academique_id = $annee_academique_id;
        $this->filiere_code        = $filiere_code;
        $this->numeroService       = new NumeroInscriptionService();
    }

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (empty($row['nom_et_prenom']) || empty($row['cin'])) continue;

            $cin    = strtoupper(trim($row['cin']));
            $nom    = trim($row['nom_et_prenom']);
            $numero = !empty($row['no_dinscription'])
                ? trim($row['no_dinscription'])
                : $this->numeroService->generate($this->filiere_code, $this->annee_academique_id);

            $email    = strtolower($cin) . '@ifp.ma';
            $password = $numero . substr($cin, 0, 2);

            $user = User::where('email', $email)->first();

            if ($user) {
                $user->update([
                    'name'     => $nom,
                    'password' => Hash::make($password),
                ]);
                $this->updated++;
            } else {
                $user = User::create([
                    'name'     => $nom,
                    'email'    => $email,
                    'password' => Hash::make($password),
                    'role'     => 'etudiant',
                ]);
                $this->created++;
            }

            Etudiant::updateOrCreate(
                ['cin' => $cin],
                [
                    'user_id'             => $user->id,
                    'groupe_id'           => $this->groupe_id,
                    'annee_academique_id' => $this->annee_academique_id,
                    'nom_prenom'          => $nom,
                    'cin'                 => $cin,
                    'numero_inscription'  => $numero,
                ]
            );
        }
    }
}