<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiService
{
    private string $apiKeyNotes;
    private string $apiKeyStudents;
    private string $apiKeyUnites;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent';

    public function __construct()
    {
        // Use env() directly to avoid cached config issues
        // Key for Formateur scanning notes (Scanner.jsx)
        $this->apiKeyFormateur = env('VITE_GEMINI_KEY_FORMATEURS', '');
        // Key for Admin scanning CIN (ScanCin.jsx)
        $this->apiKeyStudents  = env('VITE_GEMINI_KEY_STUDENTS', '');
        // Key for Admin scanning unites (Unites.jsx)
        $this->apiKeyUnites    = env('VITE_GEMINI_KEY_UNITES', '');
        // Key for Notes scan (to be provided later)
        $this->apiKeyNotes     = env('VITE_GEMINI_KEY_NOTES_TWO', '');
        
        \Log::info('GeminiService keys loaded', [
            'formateur' => $this->apiKeyFormateur ? 'yes' : 'no',
            'students'  => $this->apiKeyStudents ? 'yes' : 'no',
            'unites'    => $this->apiKeyUnites ? 'yes' : 'no',
            'notes'     => $this->apiKeyNotes ? 'yes' : 'no',
        ]);
    }

    public function scanNotes(string $base64Pdf): string
    {
        if (empty($this->apiKeyFormateur)) {
            \Log::error('Gemini API key for formateur/notes is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for notes scan (PDF)...');

        try {
            $response = Http::timeout(90)->post($this->baseUrl . '?key=' . $this->apiKeyFormateur, [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => 'You are reading a PDF document containing student grades from a nursing school in Morocco (Institut de Formation aux Professions Paramédicales).
Extract ALL students and their grades from this PDF.
Return ONLY a valid JSON array like: [{"nom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","note":14}]
Rules:
- nom: full name in French/Latin script, exactly as written in the PDF (CAPITAL LETTERS preferred)
- nom_ar: full name in Arabic script as written in the PDF. If no Arabic name is present, use an empty string ""
- note: numeric score only (0-20), extract just the number without /20
- If the grade is written as 14/20 extract only 14
- If multiple students appear in the PDF, extract ALL of them
- Read carefully both French and Arabic sections of the document
- If the PDF contains a table, extract each row as a student with their grade
- If some fields are missing or unclear, use reasonable guesses or empty strings ""
- Return ONLY the JSON array, nothing else, no markdown, no code fences, no explanation'
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'application/pdf',
                                'data'      => $base64Pdf
                            ]
                        ]
                    ]
                ]]
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                \Log::error('Gemini API error (notes): ' . json_encode($data['error']));
                return '[]';
            }

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        } catch (\Exception $e) {
            \Log::error('Gemini API exception for notes', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return '[]';
        }
    }

    public function scanCin(string $base64Pdf): string
    {
        if (empty($this->apiKeyStudents)) {
            \Log::error('Gemini API key for students/CIN is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for CIN scan (PDF)...');

        try {
            $response = Http::timeout(90)->post($this->baseUrl . '?key=' . $this->apiKeyStudents, [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => 'You are reading a PDF document containing a list of students from a Moroccan nursing school (Institut de Formation aux Professions Paramédicales).
Extract ALL students from this document.
Return ONLY a valid JSON array like: [{"nom_prenom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","cin":"AB123456","date_naissance":"1998-05-15"}]
Rules:
- nom_prenom: full name in French/Latin script, CAPITAL LETTERS exactly as written in the PDF
- nom_ar: full name in Arabic script as written in the PDF. If no Arabic name is present, use an empty string ""
- cin: CIN code exactly as written (alphanumeric, typically 8 characters). If not found use ""
- date_naissance: date of birth in YYYY-MM-DD format if found. If not found use ""
- If multiple students appear in the PDF, extract ALL of them
- Read carefully both French and Arabic sections of the document
- If the PDF contains a table, extract each row as a student
- If some fields are missing or unclear, leave them as empty strings ""
- Return ONLY the JSON array, nothing else, no markdown, no code fences, no explanation'
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'application/pdf',
                                'data'      => $base64Pdf
                            ]
                        ]
                    ]
                ]]
            ]);

            if ($response->failed()) {
                \Log::error('Gemini API failed for CIN', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return '[]';
            }

            $data = $response->json();
            \Log::info('Gemini CIN raw response', ['data' => $data]);

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return $text;

        } catch (\Exception $e) {
            \Log::error('Gemini API exception for CIN', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return '[]';
        }
    }

    public function scanUnitesDocument(string $base64Image): string
    {
        if (empty($this->apiKeyUnites)) {
            \Log::error('Gemini API key for unites is missing');
            return '[]';
        }

        try {
            \Log::info('Calling Gemini API for unites document...');
            
            $base64Length = strlen($base64Image);
            \Log::info('Base64 image length: ' . $base64Length);
            
            $payload = [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => 'This is a program document for a nursing school in Morocco (Institut de Formation Paramédicales).
                        Extract ALL Unités and their Séquences from the document.
                        Return ONLY a JSON array like:
                        [
                          {
                            "nom": "Unité name exactly as written",
                            "numero_annee": 2,
                            "semestre": 1,
                            "sequences": [
                              {"nom": "Séquence name", "coefficient": 2, "nombre_controles": 2},
                              {"nom": "Another séquence", "coefficient": 1, "nombre_controles": 1}
                            ]
                          }
                        ]
                        Rules:
                        - numero_annee: year number (1, 2, or 3) as indicated in document
                        - semestre: 1 or 2
                        - For each Unité, extract ALL its Séquences
                        - nom: exact text as written in document
                        - coefficient: numeric value for each sequence
                        - nombre_controles: number of controls (usually 2 or 3)
                        - Return ONLY the JSON array, no explanation, no markdown'
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data'      => $base64Image
                            ]
                        ]
                    ]
                ]]
            ];
            
            \Log::info('Sending request to Gemini...');
            
            $response = Http::timeout(30)->post($this->baseUrl . '?key=' . $this->apiKeyUnites, $payload);

            $data = $response->json();
            
            if (isset($data['error'])) {
                \Log::error('Gemini API error (unites): ' . json_encode($data['error']));
                return '[]';
            }
            
            \Log::info('Gemini raw response:', ['response' => $data]);
            
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini extracted text:', ['text' => $text]);
            
            \Log::info('Gemini scan-unites: returning text', ['length' => strlen($text), 'preview' => substr($text, 0, 200)]);
            
            \Log::info('GeminiService: returning to controller', ['raw_text' => $text]);
            
            return $text;
        } catch (\Exception $e) {
            \Log::error('Gemini scan-unites error: ' . $e->getMessage());
            return '[]';
        }
    }

    public function scanFormateurUnites(string $base64Image): string
    {
        if (empty($this->apiKeyUnites)) {
            \Log::error('Gemini API key for unites is missing');
            return '[]';
        }

        $response = Http::post($this->baseUrl . '?key=' . $this->apiKeyUnites, [
            'contents' => [[
                'parts' => [
                    [
                        'text' => 'This is a document listing a teacher and their assigned units/modules.
                        Extract the list of unit names.
                        Return ONLY a JSON array like: ["Unit name 1", "Unit name 2"]
                        Rules:
                        - Extract all unit/module names listed
                        - Return nothing else, no explanation, no markdown'
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data'      => $base64Image
                        ]
                    ]
                ]
            ]]
        ]);

        $data = $response->json();
        
        if (isset($data['error'])) {
            \Log::error('Gemini API error (formateur unites): ' . json_encode($data['error']));
            return '[]';
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
    }

    public function scanFormateurSequences(string $base64Image, string $prompt): string
    {
        if (empty($this->apiKeyUnites)) {
            \Log::error('Gemini API key for sequences is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for formateur sequences scan...');

        $response = Http::timeout(30)->post($this->baseUrl . '?key=' . $this->apiKeyUnites, [
            'contents' => [[
                'parts' => [
                    [
                        'text' => $prompt
                    ],
                    [
                        'inline_data' => [
                            'mime_type' => 'image/jpeg',
                            'data'      => $base64Image
                        ]
                    ]
                ]
            ]]
        ]);

        $data = $response->json();

        if (isset($data['error'])) {
            \Log::error('Gemini API error (formateur sequences): ' . json_encode($data['error']));
            return '[]';
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        \Log::info('Gemini formateur sequences raw response', ['text' => $text]);
        return $text;
    }
}
