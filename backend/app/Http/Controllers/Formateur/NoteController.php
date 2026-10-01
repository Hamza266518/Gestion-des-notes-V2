<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\SemestrePublication;
use Illuminate\Http\Request;

class NoteController extends Controller
{
    use ResolvesFormateur;
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
        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function mySequences(Request $request)
    {
        $formateur = $this->currentFormateur();
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();
        return response()->json(['success' => true, 'data' => $sequences]);
    }

    private function isPublishedForControle($controleId): bool
    {
        $controle = Controle::with('sequence.unite')->find($controleId);
        if (!$controle || !$controle->sequence || !$controle->sequence->unite) return false;

        $unite = $controle->sequence->unite;

        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) return false;

        $currentAnnee = AnneeAcademique::where('is_current', true)->first();
        if (!$currentAnnee) return false;

        $groupeIds = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $currentAnnee->id)
            ->pluck('id');

        if ($groupeIds->isEmpty()) return false;

        $types = ['bulletin'];
        if ($unite->semestre == 1) $types[] = 'notes_s1';
        else $types[] = 'notes_s2';

        return SemestrePublication::whereIn('groupe_id', $groupeIds)
            ->where('annee_academique_id', $currentAnnee->id)
            ->whereIn('type', $types)
            ->where('is_published', true)
            ->exists();
    }

    public function update(Request $request, $id)
    {
        $formateur = $this->currentFormateur();
        $note = Note::with('controle.sequence.unite')->findOrFail($id);

        $hasAccess = $formateur->sequences()->where('sequence_id', $note->controle->sequence_id)->exists();
        if (!$hasAccess) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        if ($note->is_confirmed) {
            return response()->json(['success' => false, 'message' => 'Note déjà confirmée, modification impossible'], 403);
        }

        if ($this->isPublishedForControle($note->controle_id)) {
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
            $unite = Controle::with('sequence.unite')->find($controleId)?->sequence?->unite;
            if (!$unite) {
                return response()->json(['success' => false, 'message' => 'Contrôle introuvable'], 404);
            }
            if (!$formateur->sequences()->where('sequence_id', $unite->sequence_id ?? 0)->exists()
                && \App\Models\Sequence::where('unite_id', $unite->id)->whereIn('id', $sequenceIds)->exists() === false) {
                return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
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

        $currentAnneeId = AnneeAcademique::where('is_current', true)->first()?->id;

        // When the scanner passes the control (and optionally the chosen group),
        // the suggestions are restricted to that perimeter: linking a scanned
        // row by hand must not offer a student from a sibling cohort.
        $groupeIds = null;
        if ($request->filled('controle_id')) {
            $unite = Controle::with('sequence.unite')->find($request->controle_id)?->sequence?->unite;
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
        $request->validate(['controle_id' => 'required|exists:controles,id']);
        $published = $this->isPublishedForControle($request->controle_id);
        return response()->json(['success' => true, 'data' => ['published' => $published]]);
    }

    public function scanData(Request $request)
    {
        $formateur = $this->currentFormateur();
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();

        $filieres = $sequences->pluck('unite.filiere')->filter()->unique('id')->values();

        $publicationMap = [];
        foreach ($sequences as $seq) {
            foreach ($seq->controles as $controle) {
                $publicationMap[$controle->id] = $this->isPublishedForControle($controle->id);
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
                'groupes_par_perimetre' => $groupesParPerimetre,
            ],
        ]);
    }
}