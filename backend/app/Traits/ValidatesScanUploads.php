<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

trait ValidatesScanUploads
{
    protected const SCAN_MAX_FILE_SIZE_KB = 10240;
    protected const SCAN_MAX_FILES = 10;
    protected const SCAN_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * Guard Gemini scan uploads: allowed types, per-file size and file count.
     * Returns a 422 response when invalid, null when everything is acceptable.
     */
    protected function validateScanUploads(array $files, array $mimes = self::SCAN_MIMES): ?JsonResponse
    {
        if (count($files) > self::SCAN_MAX_FILES) {
            return $this->scanUploadError(
                'Maximum ' . self::SCAN_MAX_FILES . ' fichiers par requête.',
                ['max_files' => self::SCAN_MAX_FILES]
            );
        }

        foreach ($files as $index => $file) {
            if ($file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_OK) {
                $message = match ($file->getError()) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux pour le serveur PHP (limite ' . (ini_get('upload_max_filesize') ?: 'inconnue') . ').',
                    UPLOAD_ERR_PARTIAL => 'Envoi du fichier interrompu. Réessayez.',
                    UPLOAD_ERR_NO_FILE => 'Aucun fichier reçu.',
                    default => "Échec de l'envoi du fichier.",
                };
                return $this->scanUploadError($message, ["files.{$index}" => [$message]]);
            }
        }

        $validator = Validator::make(
            ['files' => $files],
            [
                'files'   => ['required', 'array', 'min:1'],
                'files.*' => ['required', 'file', 'mimes:' . implode(',', $mimes), 'max:' . self::SCAN_MAX_FILE_SIZE_KB],
            ],
            [
                'files.*.uploaded' => "Échec de l'envoi du fichier. Vérifiez sa taille et réessayez.",
                'files.*.file'     => 'Fichier invalide ou envoi interrompu.',
                'files.*.required' => 'Fichier manquant.',
                'files.*.mimes'    => 'Type de fichier non autorisé (PDF, JPG, PNG ou WEBP uniquement).',
                'files.*.max'      => 'Fichier trop volumineux (10 Mo maximum).',
            ]
        );

        if ($validator->fails()) {
            return $this->scanUploadError(
                $validator->errors()->first(),
                $validator->errors()->toArray()
            );
        }

        return null;
    }

    private function scanUploadError(string $message, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        ], 422);
    }
}
