<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Note;
use App\Models\ScanLog;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    protected $gemini;
    protected $parser;

    public function __construct(GeminiService $gemini, NoteParserService $parser)
    {
        $this->gemini = $gemini;
        $this->parser = $parser;
    }

    public function scan(Request $request)
    {
        // verify formateur has access to this sequence
        $controle  = Controle::findOrFail($request->controle_id);
        $sequence  = $controle->sequence;
        $formateur = auth()->user()->formateur;

        $hasAccess = $formateur->sequences()->where('sequence_id', $sequence->id)->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas assigné à cette séquence',
            ], 403);
        }

        // Get only sequences assigned to this formateur for the dropdown
        // This is handled in the frontend by filtering
        // The backend already validated access above
        // Handle both 'pdfs' array, single 'pdf', or fallback to 'images'
        $validateRules = [
            'controle_id' => 'required|exists:controles,id',
        ];
        
        if ($request->hasFile('pdfs')) {
            $validateRules['pdfs'] = 'required|array';
            $validateRules['pdfs.*'] = 'required|mimes:pdf|max:10240';
        } else if ($request->hasFile('pdf')) {
            $validateRules['pdf'] = 'required|mimes:pdf|max:10240';
        } else if ($request->hasFile('images')) {
            $validateRules['images'] = 'required|array';
            $validateRules['images.*'] = 'required|image';
        } else {
            $validateRules['image'] = 'required|image';
        }
        
        $request->validate($validateRules);

        $controle  = Controle::findOrFail($request->controle_id);
        $results   = [];
        $errors    = [];
        $formateur = auth()->user()->formateur;

        // Handle multiple file input names
        if ($request->hasFile('pdfs')) {
            $files = $request->file('pdfs');
        } else if ($request->hasFile('pdf')) {
            $files = [$request->file('pdf')];
        } else if ($request->hasFile('images')) {
            $files = $request->file('images');
        } else {
            $files = [$request->file('image')];
        }

        foreach ($files as $file) {
            $base64    = base64_encode(file_get_contents($file->getRealPath()));
            $imagePath = $file->store('scans', 'public');
            $rawText   = $this->gemini->scanNotes($base64);

            $log = ScanLog::create([
                'formateur_id' => $formateur->id,
                'chemin_image' => $imagePath,
                'texte_brut'   => $rawText,
                'statut'       => 'en_attente',
            ]);

            $parsed = $this->parser->parseNotes($rawText);

            if (empty($parsed)) {
                $log->update(['statut' => 'echec']);
                $errors[] = ['image' => $imagePath, 'message' => 'Impossible de lire'];
                continue;
            }

            foreach ($parsed as $item) {
                $etudiant = null;
                $searchName = trim($item['nom']);
                if (!empty($searchName)) {
                    $safeName = str_replace(['%', '_'], ['\\%', '\\_'], strtolower($searchName));
                    $safeName = preg_replace('/\s+/', ' ', $safeName);
                    $words = array_filter(explode(' ', $safeName));

                    // Try 1: full name as substring
                    $etudiant = Etudiant::with('groupe')
                        ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $safeName . '%'])
                        ->first();

                    // Try 2: all words must appear in the name (handles reversed order)
                    if (!$etudiant && count($words) >= 2) {
                        $q = Etudiant::with('groupe');
                        foreach ($words as $w) {
                            if (strlen($w) > 1) {
                                $q->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $w . '%']);
                            }
                        }
                        $etudiant = $q->first();
                    }

                    // Try 3: first word + last word (handles middle name variations)
                    if (!$etudiant && count($words) >= 2) {
                        $first = reset($words);
                        $last  = end($words);
                        if (strlen($first) > 1 && strlen($last) > 1) {
                            $etudiant = Etudiant::with('groupe')
                                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $first . '%'])
                                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $last . '%'])
                                ->first();
                        }
                    }
                }

                $alreadyConfirmed = $etudiant
                    ? Note::where('etudiant_id', $etudiant->id)
                        ->where('controle_id', $controle->id)
                        ->where('is_confirmed', true)
                        ->exists()
                    : false;

                $results[] = [
                    'etudiant_id'       => $etudiant?->id,
                    'nom_prenom'        => $etudiant?->nom_prenom ?? $item['nom'],
                    'nom_ar'            => $etudiant?->nom_ar ?? $item['nom_ar'] ?? '',
                    'numero_inscription'=> $etudiant?->numero_inscription ?? '',
                    'groupe'            => $etudiant?->groupe?->nom ?? '',
                    'controle_id'       => $controle->id,
                    'note'              => $item['note'],
                    'chemin_image'      => $imagePath,
                    'found'             => $etudiant !== null,
                    'already_confirmed' => $alreadyConfirmed,
                ];
            }

            $log->update(['statut' => 'succes']);
        }

        return response()->json([
            'success' => true,
            'data'    => ['resultats' => $results, 'erreurs' => $errors],
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'notes'                  => 'required|array',
            'notes.*.etudiant_id'    => 'required|exists:etudiants,id',
            'notes.*.controle_id'    => 'required|exists:controles,id',
            'notes.*.valeur'         => 'required|numeric|min:0|max:20',
            'notes.*.chemin_image'   => 'nullable|string',
        ]);

        $formateur = auth()->user()->formateur;

        $alreadyConfirmed = [];

        foreach ($request->notes as $item) {
            $existing = Note::where('etudiant_id', $item['etudiant_id'])
                ->where('controle_id', $item['controle_id'])
                ->where('is_confirmed', true)
                ->first();

            if ($existing) {
                $etudiant = \App\Models\Etudiant::find($item['etudiant_id']);
                $alreadyConfirmed[] = [
                    'etudiant_id' => $item['etudiant_id'],
                    'nom_prenom'  => $etudiant?->nom_prenom ?? 'Inconnu',
                    'note_actuelle' => $existing->valeur,
                ];
            }
        }

        $alreadyIds = collect($alreadyConfirmed)->pluck('etudiant_id')->toArray();

        $saved = [];

        foreach ($request->notes as $item) {
            if (in_array($item['etudiant_id'], $alreadyIds)) continue;

            $controle = Controle::find($item['controle_id']);
            if (!$controle) continue;

            $sequence = $controle->sequence;
            $hasAccess = $formateur->sequences()->where('sequence_id', $sequence->id)->exists();

            if (!$hasAccess) continue;

            $note = Note::updateOrCreate(
                ['etudiant_id' => $item['etudiant_id'], 'controle_id' => $item['controle_id']],
                [
                    'valeur'        => $item['valeur'],
                    'chemin_image'  => $item['chemin_image'] ?? null,
                    'is_confirmed'  => true,
                    'confirmed_at'  => now(),
                ]
            );
            $saved[] = $note;
        }

        if (count($alreadyConfirmed) > 0 && count($saved) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tous les étudiants ont déjà des notes confirmées pour ce contrôle. Contactez l\'administrateur pour les modifier.',
                'already_confirmed' => $alreadyConfirmed,
                'data' => [],
            ], 409);
        }

        $msg = count($saved) . ' note(s) confirmée(s)';
        if (count($alreadyConfirmed) > 0) {
            $msg .= '. ' . count($alreadyConfirmed) . ' étudiant(s) déjà confirmé(s) ignoré(s) — contactez l\'administrateur pour les modifier.';
        }

        return response()->json([
            'success' => true,
            'data'    => $saved,
            'already_confirmed' => $alreadyConfirmed,
            'message' => $msg,
        ]);
    }
}