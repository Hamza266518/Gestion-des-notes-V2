<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unite;
use App\Models\Sequence;
use App\Services\GeminiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ScanUnitesController extends Controller
{
    protected $gemini;

    public function __construct(GeminiService $gemini)
    {
        $this->gemini = $gemini;
    }

    public function scan(Request $request)
    {
        $request->validate([
            'image'      => 'required|image',
            'filiere_id' => 'required|exists:filieres,id',
        ]);

        try {
            $base64 = base64_encode(file_get_contents($request->file('image')->getRealPath()));
             $raw = $this->gemini->scanUnitesDocument($base64);
             
            Log::info('Gemini Unites scan raw response: ' . $raw);
            Log::info('Raw response length: ' . strlen($raw));
            Log::info('Raw response preview: ' . substr($raw, 0, 500));
             
             $data = json_decode($raw, true);
              
             if (json_last_error() !== JSON_ERROR_NONE) {
                \Log::error('ScanUnitesController: JSON decode error', ['error' => json_last_error_msg(), 'raw' => $raw]);
                // Return the raw response for debugging
                return response()->json([
                    'success' => false, 
                    'message' => 'Erreur de lecture du document',
                    'debug' => [
                        'raw' => substr($raw, 0, 1000),
                        'json_error' => json_last_error_msg()
                    ]
                ]);
            }
            
            if (!is_array($data)) {
                $data = [];
            }
            
            \Log::info('ScanUnitesController: decoded data', ['count' => count($data ?? []), 'sample' => json_encode(array_slice($data ?? [], 0, 1))]);

            // Add filiere_id to each unite for preview
            foreach ($data as &$unite) {
                $unite['filiere_id'] = $request->filiere_id;
            }

            return response()->json(['success' => true, 'data' => ['resultats' => $data]]);
        } catch (\Exception $e) {
            Log::error('Scan Unites error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }

    public function confirm(Request $request)
    {
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
            $unite = Unite::create([
                'filiere_id'   => $uniteData['filiere_id'],
                'nom'          => $uniteData['nom'],
                'coefficient'  => 1, // Default, can be edited later
                'numero_annee' => $uniteData['numero_annee'],
                'semestre'     => $uniteData['semestre'],
                'is_active'    => true,
            ]);
            $created++;

            foreach ($uniteData['sequences'] as $seqData) {
                Sequence::create([
                    'unite_id'        => $unite->id,
                    'nom'             => $seqData['nom'],
                    'coefficient'     => $seqData['coefficient'],
                    'nombre_controles' => $seqData['nombre_controles'],
                    'ordre'           => 1,
                    'is_active'       => true,
                ]);
                $sequencesCreated++;
            }
        }

        return response()->json([
            'success' => true,
            'data'    => ['unites' => $created, 'sequences' => $sequencesCreated],
            'message' => "$created unités et $sequencesCreated séquences créées"
        ]);
    }
}
