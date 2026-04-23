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
        $unites = Unite::with(['sequences.controles'])
            ->when($request->filiere_id, fn($q) => $q->where('filiere_id', $request->filiere_id))
            ->when($request->numero_annee, fn($q) => $q->where('numero_annee', $request->numero_annee))
            ->when($request->semestre, fn($q) => $q->where('semestre', $request->semestre))
            ->orderBy('ordre')
            ->get();
        return response()->json(['success' => true, 'data' => $unites]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'filiere_id'   => 'required|exists:filieres,id',
            'nom'          => 'required|string',
            'coefficient'  => 'required|integer',
            'numero_annee' => 'required|integer',
            'semestre'     => 'required|integer|in:1,2',
        ]);
        $unite = Unite::create($request->all());
        return response()->json(['success' => true, 'data' => $unite, 'message' => 'Unité créée']);
    }

    public function update(Request $request, $id)
    {
        $unite = Unite::findOrFail($id);
        $unite->update($request->all());
        return response()->json(['success' => true, 'data' => $unite, 'message' => 'Unité mise à jour']);
    }

    public function destroy($id)
    {
        Unite::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Unité supprimée']);
    }

    public function toggleActive($id)
    {
        $unite = Unite::findOrFail($id);
        $unite->update(['is_active' => !$unite->is_active]);
        return response()->json(['success' => true, 'data' => $unite]);
    }

    public function import(Request $request)
    {
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