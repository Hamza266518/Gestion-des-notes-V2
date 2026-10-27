<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'admin_id',
        'admin_name',
        'action_type',
        'description',
        'model_type',
        'model_id',
        'old_value',
        'new_value',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'created_at' => 'datetime',
    ];

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Human label of a group as it reads in the journal: the filière code and
     * the year of study, then the group name ("IP 2ème année — Groupe A").
     * The DB keeps every group named "Groupe A", so the level is what tells
     * two groups of the same filière apart.
     */
    public static function groupeLabel(?Groupe $groupe): string
    {
        if (! $groupe) {
            return '';
        }

        $niveau  = $groupe->niveau;
        $filiere = $niveau?->filiere;

        $parts = [];
        if ($filiere?->code) {
            $parts[] = $filiere->code;
        }

        $numero = (int) ($niveau?->numero ?? 0);
        if ($numero > 0) {
            $parts[] = $numero === 1 ? '1ère année' : $numero . 'ème année';
        }

        $label = trim(implode(' ', $parts));
        $nom   = trim((string) ($groupe->nom ?? ''));

        if ($label !== '' && $nom !== '') {
            return "{$label} — {$nom}";
        }

        return $label !== '' ? $label : $nom;
    }

    /**
     * Comma-separated labels for a set of groups, without duplicates.
     *
     * @param  iterable<Groupe|null>  $groupes
     */
    public static function groupesLabel(iterable $groupes): string
    {
        $labels = [];
        foreach ($groupes as $groupe) {
            $label = self::groupeLabel($groupe);
            if ($label !== '') {
                $labels[$label] = true;
            }
        }

        return implode(', ', array_keys($labels));
    }
}
