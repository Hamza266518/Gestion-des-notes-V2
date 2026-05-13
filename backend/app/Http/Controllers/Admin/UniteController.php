<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\UnitesImport;
use App\Models\Unite;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class UniteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $unites = Unite::with(['sequences.controles'])
                ->when($request->filiere_id, fn($q) => $q->where('filiere_id', $request->filiere_id))
                ->when($request->numero_annee, fn($q) => $q->where('numero_annee', $request->numero_annee))
                ->when($request->semestre, fn($q) => $q->where('semestre', $request->semestre))
                ->when($request->niveau_id, function($q) use ($request) {
                    $niveau = \App\Models\Niveau::find($request->niveau_id);
                    if ($niveau) {
                        $q->where('numero_annee', $niveau->numero)
                          ->where('filiere_id', $niveau->filiere_id);
                    }
                })
                ->orderBy('ordre')
                ->get();
            return response()->json(['success' => true, 'data' => $unites]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des unités'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $request->validate([
                'filiere_id'   => 'required|exists:filieres,id',
                'nom'          => 'required|string',
                'coefficient'  => 'required|integer',
                'numero_annee' => 'required|integer',
                'semestre'     => 'required|integer|in:1,2',
            ]);
            $unite = Unite::create($request->only(['filiere_id', 'nom', 'coefficient', 'numero_annee', 'semestre', 'ordre']));
            return response()->json(['success' => true, 'data' => $unite, 'message' => 'Unité créée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de l\'unité'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $unite = Unite::findOrFail($id);
            $unite->update($request->only(['nom', 'coefficient', 'numero_annee', 'semestre', 'ordre']));
            return response()->json(['success' => true, 'data' => $unite, 'message' => 'Unité mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de l\'unité'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Unite::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Unité supprimée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression de l\'unité'], 500);
        }
    }

    public function toggleActive($id)
    {
        try {
            $unite = Unite::findOrFail($id);
            $unite->update(['is_active' => !$unite->is_active]);
            return response()->json(['success' => true, 'data' => $unite]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du changement de statut'], 500);
        }
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'file'       => 'required|mimes:xlsx,xls',
                'filiere_id' => 'required|exists:filieres,id',
            ]);

            $import = new UnitesImport($request->filiere_id);
            Excel::import($import, $request->file('file'));

            return response()->json([
                'success' => true,
                'data'    => [
                    'crees'   => $import->created,
                    'erreurs' => $import->errors,
                ],
                'message' => $import->created . ' unités importées',
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'import des unités'], 500);
        }
    }

    public function preview(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|mimes:xlsx,xls',
            ]);

            $import = new UnitesImport(0);
            $rows = Excel::toArray($import, $request->file('file'));
            $data = array_slice($rows[0], 1, 5);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la prévisualisation'], 500);
        }
    }
}
