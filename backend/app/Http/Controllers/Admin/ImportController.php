<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\EtudiantsImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function import(Request $request)
    {
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
    }

    public function preview(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        $rows = Excel::toArray([], $request->file('file'));
        $data = array_slice($rows[0], 1, 5);

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}