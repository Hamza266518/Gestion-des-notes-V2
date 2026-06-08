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
    public function index(Request $request)
    {
        $formateur = auth()->user()->formateur;
        $notes = Note::with(['etudiant.groupe', 'controle.sequence.unite'])
            ->whereHas('controle.sequence.formateurs', fn($q) =>
                $q->where('formateur_id', $formateur->id)
            )
            ->when($request->controle_id, fn($q) => $q->where('controle_id', $request->controle_id))
            ->when($request->sequence_id, fn($q) => $q->whereHas('controle', fn($q) => $q->where('sequence_id', $request->sequence_id)))
            ->when($request->groupe_id, fn($q) => $q->whereHas('etudiant', fn($q) => $q->where('groupe_id', $request->groupe_id)))
            ->get();
        return response()->json(['success' => true, 'data' => $notes]);
    }

    public function mySequences(Request $request)
    {
        $formateur = auth()->user()->formateur;
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
        $formateur = auth()->user()->formateur;
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

        $request->validate(['valeur' => 'required|numeric|min:0|max:20']);
        $note->update(['valeur' => $request->valeur]);

        return response()->json(['success' => true, 'data' => $note, 'message' => 'Note mise à jour']);
    }

    public function sequenceEtudiants($sequenceId)
    {
        $formateur = auth()->user()->formateur;
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

    public function groupes()
    {
        $formateur = auth()->user()->formateur;
        $sequenceIds = $formateur->sequences()->pluck('sequence_id');
        $uniteIds = \App\Models\Sequence::whereIn('id', $sequenceIds)->pluck('unite_id');
        $filiereIds = \App\Models\Unite::whereIn('id', $uniteIds)->pluck('filiere_id')->unique();

        $currentAnnee = \App\Models\AnneeAcademique::where('is_current', true)->first();
        if (!$currentAnnee) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $groupes = Groupe::with('niveau.filiere')
            ->whereHas('niveau', fn($q) => $q->whereIn('filiere_id', $filiereIds))
            ->where('annee_academique_id', $currentAnnee->id)
            ->orderBy('nom')
            ->get();

        return response()->json(['success' => true, 'data' => $groupes]);
    }

    public function searchEtudiants(Request $request)
    {
        $request->validate(['search' => 'required|string|min:1']);
        $search = $request->search;

        $search = strtolower($search);

        $etudiants = Etudiant::with('groupe.niveau.filiere')
            ->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $search . '%'])
                  ->orWhereRaw('LOWER(nom_ar) LIKE ?', ['%' . $search . '%'])
                  ->orWhereRaw('LOWER(numero_inscription) LIKE ?', ['%' . $search . '%']);
            })
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
        $formateur = auth()->user()->formateur;
        $sequences = $formateur->sequences()->with(['unite.filiere', 'controles'])->get();

        $filieres = $sequences->pluck('unite.filiere')->filter()->unique('id')->values();

        $publicationMap = [];
        foreach ($sequences as $seq) {
            foreach ($seq->controles as $controle) {
                $publicationMap[$controle->id] = $this->isPublishedForControle($controle->id);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'filieres' => $filieres,
                'sequences' => $sequences,
                'publication_map' => $publicationMap,
            ],
        ]);
    }
}