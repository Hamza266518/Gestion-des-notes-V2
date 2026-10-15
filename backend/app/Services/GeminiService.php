<?php

namespace App\Services;

use App\Exceptions\GeminiTransientException;
use App\Exceptions\GeminiTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GeminiService
{
    private string $apiKeyFormateur;
    private string $apiKeyStudents;
    private string $apiKeyUnites;
    private string $apiKeyTrial;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent';
    private int $timeout;

    /** HTTP statuses that mean "transiently busy", worth retrying with a backoff. */
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** Total attempts per key: 1 + this many retries after a transient status. */
    private const MAX_ATTEMPTS = 2;

    /** Last retryable status observed during the current tryKeys() run. */
    private int $lastTransientStatus = 0;

    /** True when at least one key was skipped because its quota was exhausted. */
    private bool $sawQuota = false;

    public function __construct()
    {
        $this->apiKeyFormateur = config('services.gemini.key_formateur', '');
        $this->apiKeyStudents  = config('services.gemini.key_students', '');
        $this->apiKeyUnites    = config('services.gemini.key_unites', '');
        $this->apiKeyTrial     = config('services.gemini.key_trial', '');
        $this->timeout         = (int) env('GEMINI_API_TIMEOUT', 300);
        
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
        if (empty($this->apiKeyTrial)) {
            \Log::warning('Gemini API key for trial is not configured');
        }
    }

    /**
     * Sanitizes an error message before logging: the request URL carries the
     * API key as a query parameter and must never reach the log files.
     */
    private function safeErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        $keys = array_filter([$this->apiKeyFormateur, $this->apiKeyStudents, $this->apiKeyUnites, $this->apiKeyTrial]);

        return $keys ? str_replace($keys, '***', $message) : $message;
    }

    /**
     * All configured API keys, in fallback order (primary moved first), with
     * the free-trial key as the last resort. Used by tryKeys() so every
     * Gemini call skips to the next key when one fails.
     */
    private function apiKeyPool(string $primary): array
    {
        $names = array_values(array_unique(array_merge([$primary], ['students', 'formateur', 'unites', 'trial'])));
        $pool  = [];
        foreach ($names as $name) {
            $pool[$name] = $this->{'apiKey' . ucfirst($name)} ?? '';
        }

        return $pool;
    }

    /**
     * Runs a callable against each configured key until one returns non-null.
     * The callable returns null when that key failed permanently (non-retryable
     * HTTP/API error) so the next key is tried. A GeminiTransientException
     * (429/500/502/503/504) retries the SAME key with a small backoff first.
     * ConnectionException is deliberately not caught here: it bubbles up to the
     * caller as before, since a network failure would hit every key alike.
     */
    private function tryKeys(string $primary, string $op, callable $fn): ?string
    {
        $this->lastTransientStatus = 0;
        $this->sawQuota            = false;

        foreach ($this->apiKeyPool($primary) as $name => $key) {
            if (empty($key)) {
                continue;
            }

            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                try {
                    $result = $fn($key);
                } catch (GeminiTransientException $e) {
                    $this->lastTransientStatus = $e->getCode();
                    if ($attempt < self::MAX_ATTEMPTS) {
                        $backoff = 2 * $attempt;
                        \Log::warning("Gemini {$op} transient failure (status {$e->getCode()}) with key: {$name}, retrying in {$backoff}s...");
                        sleep($backoff);
                        continue;
                    }
                    \Log::warning("Gemini {$op} key {$name} still transient-failing after retries, trying next key...");
                    break;
                }

                if ($result !== null) {
                    \Log::info("Gemini {$op} succeeded with key: {$name}");
                    return $result;
                }

                \Log::warning("Gemini {$op} failed with key: {$name}, trying next...");
                break;
            }
        }

        return null;
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

    /**
     * Returns the raw JSON text the model produced, or null when the service
     * itself could not be reached (network/TLS failure). Null is deliberate:
     * the caller distinguishes "the AI is unavailable" from "the sheet was
     * unreadable", so it must not be flattened into an empty '[]' here.
     */
    public function scanNotes(string $base64Pdf, string $context = ''): ?string
    {
        try {
            $result = $this->tryKeys('formateur', 'notes scan', fn (string $key) => $this->tryScanNotes($key, $base64Pdf, $context));
        } catch (GeminiTransportException $e) {
            \Log::error('Gemini unreachable for notes scan', ['code' => $e->getCode()]);
            return null;
        }

        if ($result === null) {
            \Log::error('All Gemini API keys failed for notes scan');
            return null;
        }

        return $result;
    }

    private function tryScanNotes(string $apiKey, string $base64Pdf, string $context = ''): ?string
    {
        try {
            $response = $this->http()->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => $this->notesScanPrompt($context)
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
        $result = $this->tryKeys('students', 'CIN scan', fn (string $key) => $this->tryScanCin($key, $base64File, $mimeType));

        if ($result === null) {
            // Every key failed: explain which families were hit instead of a
            // silent "[]".
            if ($this->sawQuota) {
                throw new GeminiTransportException('Gemini quota exhausted on all keys', 429);
            }
            if ($this->lastTransientStatus !== 0) {
                throw new GeminiTransportException('Gemini overloaded', $this->lastTransientStatus);
            }
            \Log::error('All Gemini API keys failed for CIN scan');
            return '[]';
        }

        return $result;
    }

    private function tryScanCin(string $apiKey, string $base64File, string $mimeType): ?string
    {
        \Log::info('Calling Gemini API for CIN scan', ['mime_type' => $mimeType]);

        try {
            $response = $this->http(120)->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [[
                    'parts' => [
                        [
                            'text' => $this->cinScanPrompt()
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
                $status = $response->status();
                if ($status === 429) {
                    // Limit of this key: skip to the next one, another key may
                    // still be within its quota.
                    $this->sawQuota = true;
                    \Log::warning('Gemini API key reached its limit for CIN', ['status' => 429]);
                    return null;
                }
                if (in_array($status, self::RETRYABLE_STATUSES, true)) {
                    throw new GeminiTransientException('Gemini overloaded', $status);
                }
                \Log::warning('Gemini API failed for CIN', [
                    'status' => $status
                ]);
                return null;
            }

            $data = $response->json();
            \Log::info('Gemini CIN response received', ['has_data' => isset($data['candidates'])]);

            if (isset($data['error'])) {
                // Google sometimes returns HTTP 200 with an error body.
                $errorCode = (int) ($data['error']['code'] ?? 0);
                $errorMsg  = (string) ($data['error']['message'] ?? '');
                $errorStat = (string) ($data['error']['status'] ?? '');

                if ($errorCode === 429 || $errorStat === 'RESOURCE_EXHAUSTED' || stripos($errorMsg, 'quota') !== false) {
                    $this->sawQuota = true;
                    \Log::warning('Gemini API key reached its limit for CIN', ['status' => 429]);
                    return null;
                }

                if (in_array($errorCode ?: 500, self::RETRYABLE_STATUSES, true)) {
                    throw new GeminiTransientException($errorMsg, $errorCode ?: 500);
                }

                return null;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            return $text;

        } catch (GeminiTransientException $e) {
            throw $e;
        } catch (GeminiTransportException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            \Log::error('Gemini unreachable during CIN scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::warning('Gemini API exception for CIN', [
                'exception' => get_class($e)
            ]);
            return null;
        }
    }

    public function scanUnitesDocument(string $base64Image): string
    {
        $result = $this->tryKeys('unites', 'unites scan', fn (string $key) => $this->tryScanUnitesDocument($key, $base64Image));

        if ($result === null) {
            \Log::error('All Gemini API keys failed for unites scan');
            return '[]';
        }

        return $result;
    }

    private function tryScanUnitesDocument(string $apiKey, string $base64Image): ?string
    {
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

            $response = $this->http(30)->post($this->baseUrl . '?key=' . $apiKey, $payload);

            $data = $response->json();
            
            if (isset($data['error'])) {
                \Log::warning('Gemini API error (unites): ' . json_encode($data['error']));
                return null;
            }
            
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini unites scan completed', ['length' => strlen($text)]);

            return $text;
        } catch (\Exception $e) {
            \Log::warning('Gemini scan-unites error: ' . $this->safeErrorMessage($e));
            return null;
        }
    }

    public function scanFormateurUnites(string $base64Image): string
    {
        $result = $this->tryKeys('unites', 'formateur unites scan', fn (string $key) => $this->tryScanFormateurUnites($key, $base64Image));

        return $result ?? '[]';
    }

    private function tryScanFormateurUnites(string $apiKey, string $base64Image): ?string
    {
        $response = $this->http()->post($this->baseUrl . '?key=' . $apiKey, [
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
            \Log::warning('Gemini API error (formateur unites): ' . json_encode($data['error']));
            return null;
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
    }

    public function scanExamens(array $base64Pdfs, string $context = ''): string
    {
        $result = $this->tryKeys('formateur', 'examens scan', fn (string $key) => $this->tryScanExamens($key, $base64Pdfs, $context));

        if ($result === null) {
            \Log::error('All Gemini API keys failed for examens scan');
            return '[]';
        }

        return $result;
    }

    private function tryScanExamens(string $apiKey, array $base64Pdfs, string $context = ''): ?string
    {
        \Log::info('Calling Gemini API for examens scan (' . count($base64Pdfs) . ' PDFs)...');

        $parts = [
            [
                'text' => $this->examensScanPrompt($context)
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
            $response = $this->http()->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [['parts' => $parts]]
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                \Log::warning('Gemini API error (examens)', [
                    'code' => $data['error']['code'] ?? null,
                    'status' => $data['error']['status'] ?? null,
                ]);
                return null;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini examens scan completed', ['length' => strlen($text)]);
            return $text;
        } catch (ConnectionException $e) {
            \Log::error('Gemini unreachable during examens scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::warning('Gemini API exception for examens: ' . $this->safeErrorMessage($e));
            return null;
        }
    }

    /**
     * Guess the Arabic name of a place of birth from its French spelling, used
     * when the static city dictionary has no entry. Returns '' when Gemini is
     * unreachable, misconfigured, or does not return Arabic script.
     */
    public function translateLieu(string $frenchPlace): string
    {
        $frenchPlace = trim($frenchPlace);
        if ($frenchPlace === '') {
            return '';
        }

        $result = $this->tryKeys('students', 'place translation', fn (string $key) => $this->tryTranslateLieu($key, $frenchPlace));

        if ($result === null) {
            \Log::warning('All Gemini API keys failed for place translation');
            return '';
        }

        return $result;
    }

    private function tryTranslateLieu(string $apiKey, string $frenchPlace): ?string
    {
        try {
            $response = $this->http(30)->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [[
                    'parts' => [[
                        'text' => 'You translate Moroccan place of birth (city) names from French to Arabic for a diploma.
Reply with ONLY the place name in Arabic script: no quotes, no comment, no French.
If the word is not a place you can translate, reply with exactly: -
Input: ' . $frenchPlace,
                    ]],
                ]],
            ]);

            $data = $response->json();
            if (isset($data['error'])) {
                \Log::warning('Gemini API error (place translation)', [
                    'status' => $data['error']['status'] ?? null,
                ]);
                return null;
            }

            $text = trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
            $text = trim($text, " \t\n\r\0\x0B\"'-\xEF\xBB\xBF.");

            if ($text === '' || $text === '-') {
                return '';
            }

            // Only genuine Arabic script is accepted back.
            return preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? $text : '';
        } catch (ConnectionException $e) {
            \Log::warning('Gemini unreachable during place translation', ['exception' => class_basename($e)]);
            return null;
        } catch (\Exception $e) {
            \Log::warning('Gemini API exception for place translation: ' . $this->safeErrorMessage($e));
            return null;
        }
    }

    /**
     * Prompt for the CIN / student-list scan. Field-by-field instructions so the
     * model reads each value off the right place on the card or the table.
     */
    private function cinScanPrompt(): string
    {
        return <<<'PROMPT'
You read Moroccan national identity cards (CIN) and printed student lists for a nursing school (Institut de Formation aux Professions Paramédicales). You only extract what you can actually read: an empty field is always better than an invented one.

WHAT YOU MAY RECEIVE
1. ONE card photographed twice: the FRONT is in Latin script and the BACK in Arabic script. Both faces describe the SAME person with the SAME CIN number. Merge the two faces into ONE object; never emit the person twice.
2. ONE card in a single image/PDF (front, back, or both).
3. A PDF LIST of students (a table where each ROW is one student) with columns such as NOM, PRÉNOM, CIN, DATE DE NAISSANCE, LIEU DE NAISSANCE, NATIONALITÉ. One row = one object.

FIELD LABELS TO LOOK FOR
- Latin face: NOM and PRÉNOM(S) (name), "NE(E) LE" or "DATE DE NAISSANCE" (birth date), "A" / "LIEU DE NAISSANCE" (place of birth), NATIONALITÉ, and the card number after "CARTE NATIONALE D'IDENTITÉ N°".
- Arabic face: الاسم / اللقب (name), تاريخ الازدياد (birth date), مكان الازدياد (place of birth), الجنسية (nationality), and the same card number.
- On a table/list, use the column headers to know which column is which; never assume the order.

Return ONLY a valid JSON array, for example:
[{"nom_prenom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","cin":"AB123456","cin_ar":"AB123456","date_naissance":"1998-05-15","date_naissance_ar":"15 مايو 1998","lieu_naissance":"CASABLANCA","lieu_naissance_ar":"الدار البيضاء","nationalite":"MAROCAINE","nationalite_ar":"مغربية"}]

FIELD BY FIELD
- nom_prenom: the full name in Latin script in CAPITAL LETTERS, joining the first name(s) and the last name as printed, in ONE space-separated string. Do not drop the family name and do not swap the order. Example: "FATIMA ZAHRA IDRISSI".
- nom_ar: the same person's full name in Arabic script, taken from the Arabic face. If the Arabic face is missing or unreadable, DO NOT guess and DO NOT transliterate: leave "".
- cin: the card number, UPPERCASE, letters and digits only, no spaces, no dashes, no dots. Format is one or two letters followed by five or six digits (examples: AB123456, FA206836, IP200018). Watch the look-alikes: letter O vs digit 0, letter I vs digit 1, letter S vs digit 5, letter B vs digit 8. Read digit by digit.
- cin_ar: the very same card number. The Arabic face prints the identical code, so copy cin unchanged instead of re-reading it.
- date_naissance: the birth date as YYYY-MM-DD. On the card it is printed day/month/year (JJ/MM/AAAA) or with an Arabic month name; never swap day and month. Do not confuse it with the card's issue or expiry dates.
- date_naissance_ar: the same birth date with the Arabic month name, e.g. "15 مايو 1998". Keep the digits as printed.
- lieu_naissance: the place of birth in Latin script, exactly as printed. Leave "" if it is not readable.
- lieu_naissance_ar: the place of birth in Arabic script if the Arabic face is available, otherwise "".
- nationalite: the nationality in Latin script. When the card does not clearly say otherwise, use "MAROCAINE".
- nationalite_ar: the nationality in Arabic. When it is not clearly readable, use "مغربية".

READING RULES
- A LIST may span several pages: read every student row, once each. If the same student appears on two pages, emit a single object.
- Always skip rows and lines that are not a person: titles, column headers, page numbers, totals, signatures, dates of the document.
- Never merge two different people into one object, and never split one person into two objects.
- Never invent a CIN, a date or a name: a wrong value is worse than an empty one, because empty values are simply left for the operator to type.
- Output the JSON array ONLY: no markdown, no code fences, no commentary.
PROMPT;
    }

    /**
     * Prompt for the formateur "contrôle continu" grade sheet scan. $context
     * carries what the formateur asked for (control name, mark scale, group) so
     * the model reads the right column instead of guessing.
     */
    private function notesScanPrompt(string $context): string
    {
        $contexte = '';
        if (trim($context) !== '') {
            $contexte = "\nCONTEXT OF THIS SCAN (given by the teacher):\n" . trim($context) . "\n";
        }

        return <<<'PROMPT'
You read a scanned grade sheet from a Moroccan nursing school (Institut de Formation aux Professions Paramédicales). The sheet lists the students of a class by NAME and, next to each name, the grade of ONE control.
PROMPT
            . $contexte . <<<'PROMPT'

Return ONLY a valid JSON array, for example:
[{"nom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","numero_inscription":"IP3-0125","note":14.5}]

Rules:
- Work ROW BY ROW. Each student's grade must stay on that student's own line: a grade shifted one row up or down is the worst possible error. Follow the ruled lines and the row alignment.
- Read the digits of every grade carefully (handwritten 1/7, 4/9, 5/3, 0/6/8 are the usual confusions). Never round a score toward a nicer number: read exactly what is written.
- nom: the student's full name in Latin script, exactly as printed (CAPITAL LETTERS preferred). Keep first and last names in the printed order.
- nom_ar: the same student's name in Arabic script when the sheet has an Arabic column, otherwise "".
- numero_inscription: the registration number (N° d'inscription, matricule) printed next to the name, otherwise "". Never invent one.
- note: the grade of THIS control only, as a number without the "/max" (the maximum is given in the context above, usually 20). Use a DOT as decimal separator.
  * A valid grade is a QUARTER STEP: 0, 0.25, 0.5, 0.75, 1, 1.25, ... up to the maximum. If a cell seems to read 14,7 / 14.9 / 13.3, it is a misread: re-examine the cell with the usual confusions in mind (4 vs 9, 7 vs 1, 3 vs 5). If it still does not read as a clean quarter step, output the value exactly as you see it — NEVER round it or snap it to the nearest quarter step, because that would hide the misread from the human who checks the row.
  * A fraction is written "14/20": return only the numerator (14). "14,5/20" becomes 14.5.
- ABSENCE: a cell that is empty, crossed out, struck through, or reads AB, ABS, ABSENT(E), AJ, JUSTIFIÉ(E), NON, X, "-", "N/A" or NC means the student was absent: return the string "AB" (never 0). A genuinely written 0 is the number 0.
- Extract EVERY student line, once each. Never invent students and never merge two lines into one.
- Ignore everything that is not a student grade: column headers, page numbers, totals, averages, dates, signatures.
- Return ONLY the JSON array: no markdown, no code fences, no explanation.
PROMPT;
    }

    /**
     * Prompt for the admin "examens" scan. $context names the unit/block/type
     * being scanned so the model does not read the wrong exam column.
     */
    private function examensScanPrompt(string $context): string
    {
        $contexte = '';
        if (trim($context) !== '') {
            $contexte = "\nCONTEXT OF THIS SCAN (given by the administrator):\n" . trim($context) . "\n";
        }

        return <<<'PROMPT'
You read scanned exam sheets from a Moroccan nursing school (Institut de Formation aux Professions Paramédicales). Each sheet is the official list of a class: the students by NAME and, next to each name, the grade of this exam. There are NO anonymous codes and no key sheet to decode: the printed name is the identifier.
PROMPT
            . $contexte . <<<'PROMPT'

Return ONLY a valid JSON array, for example:
[{"nom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","numero_inscription":"IP3-0125","note":14.5}]

Rules:
- Work ROW BY ROW: each student's grade must stay on their own line. A grade shifted one row is the worst possible error.
- Read the grade digits carefully and never round toward a nicer number.
- nom: full name in Latin script as printed (CAPITAL LETTERS preferred). If a line only shows part of the name, look for the missing part elsewhere on the sheet before giving up; always prefer both first and last name.
- nom_ar: full name in Arabic script when present, otherwise "".
- numero_inscription: the registration number printed next to the name, otherwise "". Never invent one.
- note: the grade of THIS exam only, as a number without the "/max" (default maximum 20). Use a DOT as decimal separator.
  * A valid grade is a QUARTER STEP: 0, 0.25, 0.5, 0.75, 1, 1.25, ... If a cell reads 14,7 / 14.9 / 13.3 it is a misread: re-read it with the usual confusions in mind (4 vs 9, 7 vs 1, 3 vs 5); if it still is not a clean quarter step, output exactly what you see — NEVER round it to the nearest quarter step, as that would hide the misread from the human who checks the row.
  * A fraction is written "14/20": return only the numerator (14). "14,5/20" becomes 14.5.
- ABSENCE: a cell that is empty, crossed out, struck through, or reads AB, ABS, ABSENT(E), AJ, JUSTIFIÉ(E), NON, X, "-", "N/A" or NC means the student was absent: return the string "AB" (never 0). A genuine 0 is the number 0.
- A sheet that only carries anonymous codes (no readable names) contributes nothing: extract no rows from it.
- Extract every readable student line once. Ignore headers, totals, averages, dates and signatures.
- Return ONLY the JSON array: no markdown, no code fences, no explanation.
PROMPT;
    }

    public function scanSoutenance(array $base64Pdfs, string $context = ''): string
    {
        $result = $this->tryKeys('formateur', 'soutenance scan', fn (string $key) => $this->tryScanSoutenance($key, $base64Pdfs, $context));

        if ($result === null) {
            \Log::error('All Gemini API keys failed for soutenance scan');
            return '[]';
        }

        return $result;
    }

    private function tryScanSoutenance(string $apiKey, array $base64Pdfs, string $context = ''): ?string
    {
        \Log::info('Calling Gemini API for soutenance scan (' . count($base64Pdfs) . ' PDFs)...');

        $parts = [
            [
                'text' => $this->soutenanceScanPrompt($context)
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
            $response = $this->http()->post($this->baseUrl . '?key=' . $apiKey, [
                'contents' => [[
                    'parts' => $parts
                ]]
            ]);

            $data = $response->json();

            if (isset($data['error'])) {
                \Log::warning('Gemini API error (soutenance)', [
                    'code'   => $data['error']['code'] ?? null,
                    'status' => $data['error']['status'] ?? null,
                ]);
                return null;
            }

            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
            \Log::info('Gemini soutenance scan completed', ['length' => strlen($text)]);
            return $text;
        } catch (ConnectionException $e) {
            \Log::error('Gemini unreachable during soutenance scan', ['exception' => class_basename($e)]);
            throw new GeminiTransportException('Gemini API unreachable', 0, $e);
        } catch (\Exception $e) {
            \Log::warning('Gemini API exception for soutenance: ' . $this->safeErrorMessage($e));
            return null;
        }
    }

    /**
     * Prompt for the soutenance (NSTI) jury sheet. Unlike the exam sheet, one
     * student line carries the grade of EVERY criterion, so each row is an
     * array of grades aligned to the criteria listed in the context.
     */
    private function soutenanceScanPrompt(string $context): string
    {
        $contexte = '';
        if (trim($context) !== '') {
            $contexte = "\nCONTEXT OF THIS SCAN (given by the administrator):\n" . trim($context) . "\n";
        }

        return <<<'PROMPT'
You read scanned "soutenance" (jury defence, NSTI) grade sheets from a Moroccan nursing school (Institut de Formation aux Professions Paramédicales). Each sheet is the official list of a class: the students by NAME and, next to each name, the grade the jury gave for EVERY criterion of the jury grid (one column per criterion). There are NO anonymous codes and no key sheet to decode: the printed name is the identifier.
PROMPT
            . $contexte . <<<'PROMPT'

Return ONLY a valid JSON array, for example:
[{"nom":"FATIMA ZAHRA IDRISSI","nom_ar":"فاطمة الزهراء الإدريسي","numero_inscription":"IP3-0125","notes":[14,12,13.5,11,15,16,14,13,12,17]}]

Rules:
- Work ROW BY ROW: each student's grades must stay on that student's own line. A value shifted one row up or down is the worst possible error.
- notes: the array of criterion grades IN THE EXACT ORDER OF THE CRITERIA GIVEN IN THE CONTEXT ABOVE. Output one entry per criterion, so the array always has as many entries as there are criteria. Use the number when the cell is graded, and null when the cell is empty or unreadable for that criterion.
- Each grade is on 20 and is a QUARTER STEP: 0, 0.25, 0.5, 0.75, 1, 1.25, ... up to 20. Read the digits carefully (handwritten 1 vs 7, 4 vs 9, 5 vs 3, 0 vs 6 vs 8 are the usual confusions). Never round a score toward a nicer number: read exactly what is written. If a cell seems to read 14,7 / 14.9 / 13.3, re-examine it with the usual confusions in mind; if it still does not read as a clean quarter step, output the value exactly as you see it — NEVER round it or snap it to the nearest quarter step, because that would hide the misread from the human who checks the row.
  * A fraction is written "14/20": return only the numerator (14). "14,5/20" becomes 14.5.
- ABSENCE: a cell that is empty, crossed out, struck through, or reads AB, ABS, ABSENT(E), AJ, JUSTIFIÉ(E), NON, X, "-", "N/A" or NC means that criterion was not graded: use null for that entry (NOT the string, and never 0). A genuinely written 0 is the number 0.
- nom: the student's full name in Latin script, exactly as printed (CAPITAL LETTERS preferred). Keep first and last names in the printed order.
- nom_ar: the same student's name in Arabic script when the sheet has an Arabic column, otherwise "".
- numero_inscription: the registration number (N° d'inscription, matricule) printed next to the name, otherwise "". Never invent one.
- Extract EVERY student line, once each. Never invent students and never split or merge two lines.
- Ignore everything that is not a student row: column headers, criterion titles, page numbers, totals, averages, dates and signatures.
- Return ONLY the JSON array: no markdown, no code fences, no explanation.
PROMPT;
    }

    public function scanFormateurSequences(string $base64Image, string $prompt): string
    {
        $result = $this->tryKeys('unites', 'formateur sequences scan', fn (string $key) => $this->tryFormateurSequences($key, $base64Image, $prompt));

        if ($result === null) {
            \Log::error('All Gemini API keys failed for formateur sequences scan');
            return '[]';
        }

        return $result;
    }

    private function tryFormateurSequences(string $apiKey, string $base64Image, string $prompt): ?string
    {
        \Log::info('Calling Gemini API for formateur sequences scan...');

        $response = $this->http(30)->post($this->baseUrl . '?key=' . $apiKey, [
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
            \Log::warning('Gemini API error (formateur sequences): ' . json_encode($data['error']));
            return null;
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        \Log::info('Gemini formateur sequences scan completed', ['length' => strlen($text)]);
        return $text;
    }
}
