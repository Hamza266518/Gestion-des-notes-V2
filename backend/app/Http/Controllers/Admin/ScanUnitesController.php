<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unite;
use App\Models\Sequence;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScanUnitesController extends Controller
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
        try {
            $request->validate([
                'image'      => 'required|image',
                'filiere_id' => 'required|exists:filieres,id',
            ]);

            $base64 = base64_encode(file_get_contents($request->file('image')->getRealPath()));
            $raw = $this->gemini->scanUnitesDocument($base64);

            $data = $this->parser->parseNotes($raw);

            if (empty($data)) {
                Log::error('ScanUnitesController: JSON decode error', ['error' => json_last_error_msg(), 'raw' => $raw]);
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur de lecture du document. Veuillez réessayer.',
                ], 500);
            }

            if (!is_array($data)) {
                $data = [];
            }

            Log::info('ScanUnitesController: decoded data', ['count' => count($data ?? []), 'sample' => json_encode(array_slice($data ?? [], 0, 1))]);

            // Add filiere_id to each unite for preview
            foreach ($data as &$unite) {
                $unite['filiere_id'] = $request->filiere_id;
            }

            return response()->json(['success' => true, 'data' => ['resultats' => $data]]);
        } catch (\Exception $e) {
            Log::error('Erreur scan unités: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan du document: ' . $e->getMessage()], 500);
        }
    }

    public function confirm(Request $request)
    {
        try {
            $request->validate([
                'unites'      => 'required|array',
                'unites.*.nom' => 'required|string',
                'unites.*.filiere_id' => 'required|exists:filieres,id',
                'unites.*.numero_annee' => 'required|integer',
                'unites.*.semestre' => 'required|integer',
                'unites.*.sequences' => 'required|array',
                'unites.*.sequences.*.nom' => 'required|string',
                'unites.*.sequences.*.coefficient' => 'required|numeric',
                'unites.*.sequences.*.nombre_controles' => 'required|integer',
            ]);

            $created = 0;
            $sequencesCreated = 0;

            foreach ($request->unites as $uniteData) {
                $coefficient = $uniteData['coefficient'] ?? 1;
                $unite = Unite::create([
                    'filiere_id'   => $uniteData['filiere_id'],
                    'nom'          => $uniteData['nom'],
                    'coefficient'  => $coefficient,
                    'numero_annee' => $uniteData['numero_annee'],
                    'semestre'     => $uniteData['semestre'],
                    'is_active'    => true,
                ]);
                $created++;

                foreach ($uniteData['sequences'] as $idx => $seqData) {
                    Sequence::create([
                        'unite_id'         => $unite->id,
                        'nom'              => $seqData['nom'],
                        'coefficient'      => $seqData['coefficient'],
                        'nombre_controles'  => $seqData['nombre_controles'],
                        'ordre'            => $idx + 1,
                        'is_active'        => true,
                    ]);
                    $sequencesCreated++;
                }
            }

            return response()->json([
                'success' => true,
                'data'    => ['unites' => $created, 'sequences' => $sequencesCreated],
                'message' => "$created unités et $sequencesCreated séquences créées"
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur confirmation unités: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'enregistrement des unités'], 500);
        }
    }
}
