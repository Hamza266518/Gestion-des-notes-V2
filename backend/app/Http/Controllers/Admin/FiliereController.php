<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Filiere;
use Illuminate\Http\Request;

class FiliereController extends Controller
{
    public function index()
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => Filiere::with(['niveaux.groupes'])->get(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des filières'], 500);
        }
    }

    public function store(Request $request)
    {
        // Outside the try on purpose: validate() throws ValidationException,
        // which extends Exception, so catching it here turned every bad payload
        // into a 500 instead of a 422 naming the offending field.
        $request->validate([
            'nom'            => 'required|string',
            'code'           => 'required|string|unique:filieres,code',
            'section'        => 'required|string',
            'type_formation' => 'nullable|in:Qualification,Technicien,Specialisation',
            'nombre_annees'  => 'required|integer|min:1|max:5',
        ]);

        try {
            $data = $request->only(['nom', 'nom_ar', 'code', 'section', 'nombre_annees']);
            $type = $request->input('type_formation');
            if (!$type && $request->filled('section')) {
                $section = strtoupper(trim((string) $request->section));
                if (in_array($section, ['Q', 'QUALIFICATION'], true)) $type = 'Qualification';
                elseif (in_array($section, ['T', 'TECHNICIEN'], true)) $type = 'Technicien';
                elseif (in_array($section, ['TS', 'SPECIALISATION', 'TECHNICIEN SPECIALISE'], true)) $type = 'Specialisation';
            }
            if ($type) $data['type_formation'] = $type;
            $filiere = Filiere::create($data);
            return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière créée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la création de la filière'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        // Outside the try for the same reason as store(): a ValidationException
        // caught here became a 500 instead of a 422.
        $request->validate([
            'code' => 'string|unique:filieres,code,' . $id,
            'type_formation' => 'nullable|in:Qualification,Technicien,Specialisation',
        ]);

        try {
            $filiere = Filiere::findOrFail($id);
            $data = $request->only(['nom', 'nom_ar', 'code', 'section', 'nombre_annees']);
            $type = $request->input('type_formation');
            if (!$type && $request->filled('section')) {
                $section = strtoupper(trim((string) $request->section));
                if (in_array($section, ['Q', 'QUALIFICATION'], true)) $type = 'Qualification';
                elseif (in_array($section, ['T', 'TECHNICIEN'], true)) $type = 'Technicien';
                elseif (in_array($section, ['TS', 'SPECIALISATION', 'TECHNICIEN SPECIALISE'], true)) $type = 'Specialisation';
            }
            if ($type) $data['type_formation'] = $type;
            $filiere->update($data);
            return response()->json(['success' => true, 'data' => $filiere, 'message' => 'Filière mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la mise à jour de la filière'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            Filiere::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Filière supprimée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la suppression de la filière'], 500);
        }
    }
}
