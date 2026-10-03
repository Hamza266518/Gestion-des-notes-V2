<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Services\PublicationLockService;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    use ResolvesFormateur;

    public function __construct(private PublicationLockService $locks)
    {
    }


    public function index(Request $request)
    {
        $formateur = $this->currentFormateur();
        $currentAnneeId = AnneeAcademique::where('is_current', true)->first()?->id;
        $notes = Note::with(['etudiant.groupe', 'controle.sequence.unite'])
            ->whereHas('controle.sequence.formateurs', fn($q) =>
                $q->where('formateur_id', $formateur->id)
            )
            ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
            ->when($request->sequence_id, fn($q) => $q->whereHas('controle', fn($q) => $q->where('sequence_id', $request->sequence_id)))
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->when($currentAnneeId, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('annee_academique_id', $currentAnneeId)))
            ->get();

        // A single level can be partly published, so the lock is resolved per
        // note from its own group. The service memoises per unité, so this stays
        // at one query per sequence rather than one per grade.
        $notes->each(function ($note) {
            $note->setAttribute(
                'verrouille',
                $this->locks->verrouilleGroupe($note->controle, $note->etudiant?->groupe_id)
            );
        });

        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function mySequences(Request $request)
    {
        $formateur = $this->currentFormateur();
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();
        return response()->json(['success' => true, 'data' => $sequences]);
    }

    public function update(Request $request, $id)
    {
        $formateur = $this->currentFormateur();
        $note = Note::with(['controle.sequence.unite', 'etudiant'])->findOrFail($id);

        $hasAccess = $formateur->sequences()->where('sequence_id', $note->controle->sequence_id)->exists();
        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        if ($note->is_confirmed) {
            return response()->json(['success' => false, 'message' => 'Note déjà confirmée, modification impossible'], 403);
        }

        // The student's own group decides, not the whole level: a sibling cohort
        // being published must not close this grade.
        if ($this->locks->verrouilleGroupe($note->controle, $note->etudiant?->groupe_id)) {
            return response()->json(['success' => false, 'message' => 'Les notes/bulletins ont été publiés pour ce groupe. Modification impossible.'], 403);
        }

        // Each control carries its own scale (note_max).
        $noteMax = $note->controle?->note_max ?: 20;
        $estAbsent = $request->boolean('est_absent');
        $request->validate([
            'valeur'     => [$estAbsent ? 'nullable' : 'required', 'numeric', 'min:0', "max:{$noteMax}"],
            'est_absent' => 'nullable|boolean',
        ]);
        $note->update([
            'valeur'     => $estAbsent ? 0 : $request->valeur,
            'est_absent' => $estAbsent,
        ]);

        return response()->json(['success' => true, 'data' => $note, 'message' => $estAbsent ? 'Marqué absent' : 'Note mise à jour']);
    }

    public function sequenceEtudiants($sequenceId)
    {
        $formateur = $this->currentFormateur();
        $sequence = \App\Models\Sequence::with('unite')->findOrFail($sequenceId);

        $hasAccess = $formateur->sequences()->where('sequence_id', $sequenceId)->exists();
        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        $unite = $sequence->unite;
        $niveau = \App\Models\Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();

        if (!$niveau) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $currentAnnee = \App\Models\AnneeAcademique::where('is_current', true)->first();
        if (!$currentAnnee) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $groupeIds = \App\Models\Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $currentAnnee->id)
            ->pluck('id');

        $etudiants = \App\Models\Etudiant::with('groupe')
            ->whereIn('groupe_id', $groupeIds)
            ->orderBy('nom_prenom')
            ->get();

        return response()->json(['success' => true, 'data' => $etudiants]);
    }

    public function groupes(Request $request)
    {
        $formateur = $this->currentFormateur();
        $sequenceIds = $formateur->sequences()->pluck('sequence_id');

        $filiereIds = \App\Models\Unite::whereIn('id',
            \App\Models\Sequence::whereIn('id', $sequenceIds)->pluck('unite_id')
        )->pluck('filiere_id')->unique();

        $currentAnnee = \App\Models\AnneeAcademique::where('is_current', true)->first();
        if (!$currentAnnee) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $groupes = Groupe::with('niveau.filiere')
            ->whereHas('niveau', fn($q) => $q->whereIn('filiere_id', $filiereIds))
            ->where('annee_academique_id', $currentAnnee->id)
            ->orderBy('nom')
            ->get();

        // Optional: restrict to the filière/année of one control, so the scan
        // dialog only offers groups the backend will actually accept.
        if ($controleId = $request->query('controle_id')) {
            $controle = Controle::with('sequence.unite')->find($controleId);
            $unite = $controle?->sequence?->unite;
            if (!$unite) {
                return response()->json(['success' => false, 'message' => 'Contrôle introuvable'], 404);
            }
            // Ownership of the control's own sequence. The previous check tested
            // `$unite->sequence_id`, a column Unite does not have, so it always
            // compared against 0 and could never grant or deny anything.
            if (!$formateur->sequences()->where('sequence_id', $controle->sequence_id)->exists()) {
                return response()->json(['success' => false, 'message' => 'Accès refusé à ce contrôle.'], 403);
            }

            $groupes = $groupes->filter(fn($g) => $g->niveau?->filiere_id === $unite->filiere_id
                && (string) $g->niveau?->numero === (string) $unite->numero_annee
            )->values();
        }

        return response()->json(['success' => true, 'data' => $groupes]);
    }

    public function searchEtudiants(Request $request)
    {
        $request->validate([
            'search'      => 'required|string|min:1',
            'controle_id' => 'nullable|integer',
            'groupe_id'   => 'nullable|integer',
        ]);
        $search = strtolower($request->search);

        $formateur = $this->currentFormateur();
        $sequenceIds = $formateur->sequences()->pluck('sequence_id');

        $currentAnneeId = AnneeAcademique::where('is_current', true)->first()?->id;

        // When the scanner passes the control (and optionally the chosen group),
        // the suggestions are restricted to that perimeter: linking a scanned
        // row by hand must not offer a student from a sibling cohort.
        $groupeIds = null;
        if ($request->filled('controle_id')) {
            $controle = Controle::with('sequence.unite')->find($request->controle_id);

            if (!$controle?->sequence) {
                return response()->json(['success' => false, 'message' => 'Contrôle introuvable'], 404);
            }

            // The control selects which students may be offered, so it has to
            // belong to the caller. Without this any instructor could pass an
            // arbitrary control id and read a foreign cohort's roster.
            if (!$formateur->sequences()->where('sequence_id', $controle->sequence_id)->exists()) {
                return response()->json(['success' => false, 'message' => 'Accès refusé à ce contrôle.'], 403);
            }

            $unite = $controle->sequence->unite;
            $niveau = $unite
                ? Niveau::where('filiere_id', $unite->filiere_id)->where('numero', $unite->numero_annee)->first()
                : null;

            if ($niveau && $currentAnneeId) {
                $groupeIds = Groupe::where('niveau_id', $niveau->id)
                    ->where('annee_academique_id', $currentAnneeId)
                    ->pluck('id');

                if ($request->filled('groupe_id')) {
                    $groupeIds = $groupeIds->contains((int) $request->groupe_id)
                        ? collect([(int) $request->groupe_id])
                        : collect();
                }
            } else {
                $groupeIds = collect();
            }
        } else {
            // No control to scope by, so fall back to the instructor's own
            // perimeter. Leaving this null searched every filière of the school.
            $filiereIds = \App\Models\Unite::whereIn('id',
                \App\Models\Sequence::whereIn('id', $sequenceIds)->pluck('unite_id')
            )->pluck('filiere_id')->unique();

            $groupeIds = Groupe::whereHas('niveau', fn($q) => $q->whereIn('filiere_id', $filiereIds))
                ->when($currentAnneeId, fn($q) => $q->where('annee_academique_id', $currentAnneeId))
                ->pluck('id');
        }

        $etudiants = Etudiant::with('groupe.niveau.filiere')
            ->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $search . '%'])
                  ->orWhereRaw('LOWER(nom_ar) LIKE ?', ['%' . $search . '%'])
                  ->orWhereRaw('LOWER(numero_inscription) LIKE ?', ['%' . $search . '%']);
            })
            ->when($currentAnneeId, fn($q) => $q->where('annee_academique_id', $currentAnneeId))
            ->when($groupeIds !== null, fn($q) => $q->whereIn('groupe_id', $groupeIds))
            ->orderBy('nom_prenom')
            ->limit(15)
            ->get();

        return response()->json(['success' => true, 'data' => $etudiants]);
    }

    public function publicationStatus(Request $request)
    {
        $request->validate([
            'controle_id' => 'required|exists:controles,id',
            'groupe_id'   => 'nullable|integer',
        ]);

        $controle = Controle::with('sequence.unite')->find($request->controle_id);
        if (!$controle) {
            return response()->json(['success' => false, 'message' => 'Contrôle introuvable'], 404);
        }

        // Without this the endpoint answers for any control id in the database,
        // handing a formateur the publication state and the group ids of a level
        // they were never assigned to. Same guard as update()/groupes().
        $formateur = $this->currentFormateur();
        if (!$formateur->sequences()->where('sequence_id', $controle->sequence_id)->exists()) {
            return response()->json(['success' => false, 'message' => 'Accès refusé à ce contrôle'], 403);
        }

        $groupeId = $request->filled('groupe_id') ? (int) $request->groupe_id : null;

        return response()->json(['success' => true, 'data' => [
            // Scoped to the chosen group when the caller names one, so a partly
            // published level is not reported as fully closed.
            'published'             => $this->locks->verrouillePour($controle, $groupeId),
            'published_all'         => $this->locks->verrouilleTout($controle),
            'groupes_verrouilles'   => $this->locks->groupesVerrouilles($controle),
        ]]);
    }

    public function scanData(Request $request)
    {
        $formateur = $this->currentFormateur();
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();

        $filieres = $sequences->pluck('unite.filiere')->filter()->unique('id')->values();

        $publicationMap = [];
        $publicationMapGroupes = [];
        foreach ($sequences as $seq) {
            foreach ($seq->controles as $controle) {
                // Closed for every group in range: enough to grey the control out
                // in the picker. The per-group detail below is what the group
                // selector uses once a cohort is chosen.
                $publicationMap[$controle->id] = $this->locks->verrouilleTout($controle);
                $publicationMapGroupes[$controle->id] = $this->locks->groupesVerrouilles($controle);
            }
        }

        // Groups of the current year, keyed by filière/année so the scanner can
        // offer the exact cohort of the chosen control and match only inside it.
        $currentAnnee = \App\Models\AnneeAcademique::where('is_current', true)->first();
        $groupesParPerimetre = [];
        if ($currentAnnee) {
            foreach (Groupe::with('niveau')
                ->where('annee_academique_id', $currentAnnee->id)
                ->orderBy('nom')
                ->get() as $groupe) {
                $key = $groupe->niveau?->filiere_id . '|' . $groupe->niveau?->numero;
                $groupesParPerimetre[$key][] = [
                    'id'         => $groupe->id,
                    'nom'        => $groupe->nom,
                    'filiere_id' => $groupe->niveau?->filiere_id,
                    'numero'     => $groupe->niveau?->numero,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'filieres' => $filieres,
                'sequences' => $sequences,
                'publication_map' => $publicationMap,
                'publication_map_groupes' => $publicationMapGroupes,
                'groupes_par_perimetre' => $groupesParPerimetre,
            ],
        ]);
    }
}