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
        // verify formateur has access to this unite
        $controle  = Controle::findOrFail($request->controle_id);
        $unite     = $controle->sequence->unite;
        $formateur = auth()->user()->formateur;

        $hasAccess = $formateur->unites()->where('unite_id', $unite->id)->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas assigné à cette unité',
            ], 403);
        }
        $request->validate([
            'images'      => 'required|array',
            'images.*'    => 'required|image',
            'controle_id' => 'required|exists:controles,id',
        ]);

        $controle  = Controle::findOrFail($request->controle_id);
        $results   = [];
        $errors    = [];
        $formateur = auth()->user()->formateur;

        foreach ($request->file('images') as $image) {
            $base64    = base64_encode(file_get_contents($image->getRealPath()));
            $imagePath = $image->store('scans', 'public');
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
                $etudiant = Etudiant::whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . strtolower($item['nom']) . '%'])->first();

                $results[] = [
                    'etudiant_id'  => $etudiant?->id,
                    'nom_prenom'   => $etudiant?->nom_prenom ?? $item['nom'],
                    'controle_id'  => $controle->id,
                    'note'         => $item['note'],
                    'chemin_image' => $imagePath,
                    'found'        => $etudiant !== null,
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
            'notes.*.note'           => 'required|numeric|min:0|max:20',
            'notes.*.chemin_image'   => 'nullable|string',
        ]);

        $saved = [];

        foreach ($request->notes as $item) {
            $existing = Note::where('etudiant_id', $item['etudiant_id'])
                ->where('controle_id', $item['controle_id'])
                ->where('is_confirmed', true)
                ->first();

            if ($existing) continue; // already confirmed skip

            $note = Note::updateOrCreate(
                ['etudiant_id' => $item['etudiant_id'], 'controle_id' => $item['controle_id']],
                [
                    'valeur'        => $item['note'],
                    'chemin_image'  => $item['chemin_image'] ?? null,
                    'is_confirmed'  => true,
                    'confirmed_at'  => now(),
                ]
            );
            $saved[] = $note;
        }

        return response()->json([
            'success' => true,
            'data'    => $saved,
            'message' => count($saved) . ' notes confirmées',
        ]);
    }
}