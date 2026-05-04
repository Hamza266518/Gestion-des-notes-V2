<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\EtudiantsImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function import(Request $request)
    {
        try {
            $request->validate([
                'file'                => 'required|mimes:xlsx,xls',
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'filiere_code'        => 'required|string',
            ]);

            $import = new EtudiantsImport(
                $request->groupe_id,
                $request->annee_academique_id,
                $request->filiere_code
            );

            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'data'    => [
                    'crees'      => $import->created,
                    'mis_a_jour' => $import->updated,
                    'erreurs'    => $import->errors,
                ],
                'message' => 'Import terminé avec succès',
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur import étudiants: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'importation: ' . $e->getMessage()], 500);
        }
    }

    public function preview(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls',
            ]);

            $rows = Excel::toArray([], $request->file('file'));
            $data = array_slice($rows[0], 1, 5);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur aperçu import: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'aperçu du fichier'], 500);
        }
    }
}