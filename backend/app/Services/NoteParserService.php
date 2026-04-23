<?php

namespace App\Services;

class NoteParserService
{
    public function parseNotes(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);
        return is_array($data) ? $data : [];
    }

    public function parseCin(string $rawText): array
    {
        $clean = preg_replace('/```json|```/', '', $rawText);
        $data  = json_decode(trim($clean), true);
        return is_array($data) ? $data : [];
    }
}