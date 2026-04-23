<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = env('GEMINI_API_KEY');
    }

    public function scanNotes(string $base64Image): string
    {
        $response = Http::post($this->baseUrl . '?key=' . $this->apiKey, [
            'contents' => [[
                'parts' => [
                    [
                        'text' => 'This is a student grade paper from a nursing school in Morocco.
                        Extract the student full name and their numeric score.
                        Return ONLY a JSON array like: [{"nom":"Full Name","note":14}]
                        Rules:
                        - nom: full name exactly as written
                        - note: numeric score only (no /20)
                        - If multiple students return all
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

        return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
    }

    public function scanCin(string $base64Image): string
    {
        $response = Http::post($this->baseUrl . '?key=' . $this->apiKey, [
            'contents' => [[
                'parts' => [
                    [
                        'text' => 'This is a Moroccan CIN (national identity card).
                        Extract the person information.
                        Return ONLY a JSON object like: {"nom":"Full Name","cin":"AB123456"}
                        Rules:
                        - nom: full name in capital letters as on card
                        - cin: the CIN number/code exactly
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

        return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    }
    public function scanFormateurUnites(string $base64Image): string
{
    $response = Http::post($this->baseUrl . '?key=' . $this->apiKey, [
        'contents' => [[
            'parts' => [
                [
                    'text' => 'This is a document listing a teacher and their assigned units/modules.
                    Extract the list of unit names.
                    Return ONLY a JSON array like: ["Unite name 1", "Unite name 2"]
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

    return $response->json()['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
}
}