<?php

namespace App\Services;

use App\Exceptions\GeminiTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private string $apiKeyFormateur;
    private string $apiKeyStudents;
    private string $apiKeyUnites;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent';
    private int $timeout;

    public function __construct()
    {
        $this->apiKeyFormateur = config('services.gemini.key_formateur', '');
        $this->apiKeyStudents  = config('services.gemini.key_students', '');
        $this->apiKeyUnites    = config('services.gemini.key_unites', '');
        $this->timeout         = (int) env('GEMINI_API_TIMEOUT', 90);
        
        // Warn if API keys are missing
        if (empty($this->apiKeyFormateur)) {
            \Log::warning('Gemini API key for formateurs is not configured');
        }
        if (empty($this->apiKeyStudents)) {
            \Log::warning('Gemini API key for students is not configured');
        }
        if (empty($this->apiKeyUnites)) {
            \Log::warning('Gemini API key for unites is not configured');
        }
    }

    /**
     * Sanitizes an error message before logging: the request URL carries the
     * API key as a query parameter and must never reach the log files.
     */
    private function safeErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        $keys = array_filter([$this->apiKeyFormateur, $this->apiKeyStudents, $this->apiKeyUnites]);

        return $keys ? str_replace($keys, '***', $message) : $message;
    }

    /**
     * HTTP client with TLS verification ALWAYS enabled.
     * On hosts without a system CA bundle (e.g. some Windows PHP installs),
     * CA_BUNDLE_PATH points to a ca-bundle.crt file instead of disabling
     * certificate verification, which would expose the API key to MITM.
     */
    private function http(?int $timeout = null): PendingRequest
    {
        $request = Http::timeout($timeout ?? $this->timeout);

        $bundle = (string) config('services.gemini.ca_bundle', '');
        if ($bundle !== '' && file_exists($bundle)) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        return $request;
    }

    public function scanNotes(string $base64Pdf): string
    {
        $keys = [
            'formateur' => $this->apiKeyFormateur,
            'students'  => $this->apiKeyStudents,
            'unites'    => $this->apiKeyUnites,
        ];

        foreach ($keys as $name => $key) {
            if (empty($key)) {
                continue;
            }

            $result = $this->tryScanNotes($key, $base64Pdf);
            if ($result !== null) {
                \Log::info("Gemini notes scan succeeded with key: {$name}");
                return $result;
            }

            \Log::warning("Gemini notes scan failed with key: {$name}, trying next...");
        }

        \Log::error('All Gemini API keys failed for notes scan');
        return '[]';
    }

    private function tryScanNotes(string $apiKey, string $base64Pdf): ?string
    {
        try {
            $response = $this->http()->post($this->baseUrl . '?key=' . $apiKey, [
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
                \Log::warning('Gemini API error (notes)', [
                    'code' => $data['error']['code'] ?? null,
                    'status' => $data['error']['status'] ?? null,
                ]);
                return null;
            }

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        } catch (ConnectionException $e) {
            // Network/TLS failure: retrying with the other keys would fail the same way
            \Log::warning('Gemini unreachable during notes scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::warning('Gemini API exception for notes: ' . $this->safeErrorMessage($e));
            return null;
        }
    }

    public function scanCin(string $base64File, string $mimeType = 'application/pdf'): string
    {
        if (empty($this->apiKeyStudents)) {
            \Log::error('Gemini API key for students/CIN is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for CIN scan', ['mime_type' => $mimeType]);

        try {
            $response = $this->http()->post($this->baseUrl . '?key=' . $this->apiKeyStudents, [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => 'You read Moroccan national identity cards (CIN) for a nursing school (Institut de Formation aux Professions Paramédicales).

A Moroccan CIN is ONE single physical card. Its FRONT is printed in Latin script and its BACK in Arabic script. Both faces carry the SAME information: the holder name, the CIN number, the date of birth, the place of birth and the nationality. They are never two different people and never two different numbers.

Each file you receive is one card (or a PDF containing several cards). Extract ONE object per card.

Return ONLY a valid JSON array, e.g.:
[{"nom_prenom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","cin":"AB123456","cin_ar":"AB123456","date_naissance":"1998-05-15","date_naissance_ar":"15 مايو 1998","lieu_naissance":"CASABLANCA","lieu_naissance_ar":"الدار البيضاء","nationalite":"MAROCAINE","nationalite_ar":"مغربية"}]

Rules:
- The same card may be supplied twice, once photographed from the front and once from the back. Treat both files as the SAME student: merge them into a single object, never emit the person twice.
- nom_prenom: full name in Latin script, CAPITALISED as printed.
- nom_ar: the same person name in Arabic script, taken from the Arabic face of the card. If the Arabic face is not supplied, fall back to the Latin name in Latin script instead of leaving it empty.
- cin: the CIN code, uppercase, letters and digits only, no spaces or dashes.
- cin_ar: the very same code. The Arabic face prints the identical number, so copy cin unchanged rather than re-typing it.
- date_naissance: date of birth as YYYY-MM-DD. Read the printed day and month, they are numeric on the card.
- date_naissance_ar: the same date written with an Arabic month name, e.g. 15 مايو 1998. Do not translate the digits to another numbering system; keep them as printed.
- lieu_naissance: place of birth in Latin script. lieu_naissance_ar: the same place in Arabic if the Arabic face is supplied, otherwise leave it empty.
- nationalite: nationality in Latin script. If the card is not readable on this point, use "MAROCAINE".
- nationalite_ar: the same nationality in Arabic, for example "مغربية". If it is not readable, use "مغربية".
- Never invent a second person from the back of a card, and never merge two different people.
- If a field is genuinely unreadable, use an empty string, except nationalite which defaults to MAROCAINE.
- Output the JSON array only: no markdown, no code fences, no commentary.'
                        ],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data'      => $base64File
                            ]
                        ]
                    ]
                ]]
            ]);

            if ($response->failed()) {
                \Log::error('Gemini API failed for CIN', [
                    'status' => $response->status()
                ]);
                return '[]';
            }

            $data = $response->json();
            \Log::info('Gemini CIN response received', ['has_data' => isset($data['candidates'])]);

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return $text;

        } catch (ConnectionException $e) {
            \Log::error('Gemini unreachable during CIN scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::error('Gemini API exception for CIN', [
                'exception' => get_class($e)
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

            $response = $this->http(30)->post($this->baseUrl . '?key=' . $this->apiKeyUnites, $payload);

            $data = $response->json();
            
            if (isset($data['error'])) {
                \Log::error('Gemini API error (unites): ' . json_encode($data['error']));
                return '[]';
            }
            
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini unites scan completed', ['length' => strlen($text)]);

            return $text;
        } catch (\Exception $e) {
            \Log::error('Gemini scan-unites error: ' . $this->safeErrorMessage($e));
            return '[]';
        }
    }

    public function scanFormateurUnites(string $base64Image): string
    {
        if (empty($this->apiKeyUnites)) {
            \Log::error('Gemini API key for unites is missing');
            return '[]';
        }

        $response = $this->http()->post($this->baseUrl . '?key=' . $this->apiKeyUnites, [
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

    public function scanExamens(array $base64Pdfs): string
    {
        if (empty($this->apiKeyFormateur)) {
            \Log::error('Gemini API key for formateur/examens is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for examens scan (' . count($base64Pdfs) . ' PDFs)...');

        $parts = [
            [
                'text' => 'You are reading multiple PDF documents containing exam results from a nursing school in Morocco (Institut de Formation aux Professions Paramédicales).
Some PDFs are KEY SHEETS that map anonymous codes to student identities (name, CIN).
Other PDFs are GRADED EXAM SHEETS showing anonymous codes with their scores.
Extract ALL students by matching codes across documents.
Return ONLY a valid JSON array like: [{"code":"01","nom_prenom":"FATIMA ZAHRA IDRISSI","cin":"AB123456","note":14}]
Rules:
- code: the anonymous code/number written on both the key sheet and graded sheet
- nom_prenom: full name in French/Latin script from the key sheet, CAPITAL LETTERS preferred. If the name appears incomplete (e.g. only first name "AHMED" without a last name), search the document for the missing parts (last name may appear elsewhere) and combine them. Always prefer extracting BOTH first and last name.
- cin: CIN code if available from the key sheet, otherwise empty string ""
- note: numeric score only (0-20), extract just the number without /20
- If a grade is written as 14/20 extract only 14
- Match each code from graded sheets to the corresponding student on the key sheet
- If no key sheet is found, return what you can with empty nom_prenom and code as identifier
- If some fields are missing or unclear, use empty strings ""
- Return ONLY the JSON array, nothing else, no markdown, no code fences, no explanation'
            ]
        ];

        foreach ($base64Pdfs as $base64) {
            $parts[] = [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data'      => $base64
                ]
            ];
        }

        try {
            $response = $this->http()->post($this->baseUrl . '?key=' . $this->apiKeyFormateur, [
                'contents' => [['parts' => $parts]]
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                \Log::error('Gemini API error (examens)', [
                    'code' => $data['error']['code'] ?? null,
                    'status' => $data['error']['status'] ?? null,
                ]);
                return '[]';
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini examens scan completed', ['length' => strlen($text)]);
            return $text;
        } catch (ConnectionException $e) {
            \Log::error('Gemini unreachable during examens scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::error('Gemini API exception for examens: ' . $this->safeErrorMessage($e));
            return '[]';
        }
    }

    public function scanFormateurSequences(string $base64Image, string $prompt): string
    {
        if (empty($this->apiKeyUnites)) {
            \Log::error('Gemini API key for sequences is missing');
            return '[]';
        }

        \Log::info('Calling Gemini API for formateur sequences scan...');

        $response = $this->http(30)->post($this->baseUrl . '?key=' . $this->apiKeyUnites, [
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
        \Log::info('Gemini formateur sequences scan completed', ['length' => strlen($text)]);
        return $text;
    }
}
