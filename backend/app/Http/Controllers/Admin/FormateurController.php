<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Imports\FormateurUnitesImport;
use App\Models\Formateur;
use App\Models\Unite;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\FormateursImport;
class FormateurController extends Controller
{
    protected $gemini;
    protected $parser;

    public function __construct(GeminiService $gemini, NoteParserService $parser)
    {
        $this->gemini = $gemini;
        $this->parser = $parser;
    }

    // list all formateurs with their unites
    public function index()
    {
        $formateurs = Formateur::with(['user', 'unites.filiere'])->get();
        return response()->json(['success' => true, 'data' => $formateurs]);
    }

    // create new formateur
    public function store(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
            'role'     => 'formateur',
        ]);

        $formateur = Formateur::create(['user_id' => $user->id]);

        return response()->json([
            'success' => true,
            'data'    => $formateur->load('user'),
            'message' => 'Formateur créé',
        ]);
    }


// import formateurs from Excel
public function import(Request $request)
{
    $request->validate([
        'file' => 'required|mimes:xlsx,xls',
    ]);

    $import = new FormateursImport();
    Excel::import($import, $request->file('file'));

    return response()->json([
        'success' => true,
        'data'    => [
            'crees'      => $import->created,
            'mis_a_jour' => $import->updated,
            'erreurs'    => $import->errors,
        ],
        'message' => $import->created . ' formateurs importés',
    ]);
}

// preview Excel before importing
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
    // delete formateur
    public function destroy($id)
    {
        $formateur = Formateur::findOrFail($id);
        $formateur->user()->delete();
        $formateur->delete();
        return response()->json(['success' => true, 'message' => 'Formateur supprimé']);
    }

    // import unites from Excel file
    public function importUnites(Request $request, $id)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls',
        ]);

        $import = new FormateurUnitesImport($id);
        Excel::import($import, $request->file('file'));

        return response()->json([
            'success' => true,
            'data'    => [
                'assignees' => $import->assigned,
                'erreurs'   => $import->errors,
            ],
            'message' => $import->assigned . ' unités assignées',
        ]);
    }

    // scan document to assign unites
    public function scanUnites(Request $request, $id)
    {
        $request->validate([
            'image' => 'required|image',
        ]);

        $formateur = Formateur::findOrFail($id);
        $base64    = base64_encode(file_get_contents($request->file('image')->getRealPath()));
        $rawText   = $this->gemini->scanFormateurUnites($base64);

        // parse list of unite names
        $clean     = preg_replace('/```json|```/', '', $rawText);
        $uniteNoms = json_decode(trim($clean), true) ?? [];

        $assigned = [];
        $errors   = [];

        foreach ($uniteNoms as $nom) {
            $unite = Unite::whereRaw('LOWER(nom) LIKE ?', ['%' . strtolower($nom) . '%'])->first();

            if (!$unite) {
                $errors[] = 'Unité non trouvée: ' . $nom;
                continue;
            }

            $formateur->unites()->syncWithoutDetaching([$unite->id]);
            $assigned[] = $unite->nom;
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'assignees' => $assigned,
                'erreurs'   => $errors,
            ],
            'message' => count($assigned) . ' unités assignées',
        ]);
    }

    // remove one unite from formateur
    public function removeUnite(Request $request, $id)
    {
        $request->validate(['unite_id' => 'required|exists:unites,id']);
        $formateur = Formateur::findOrFail($id);
        $formateur->unites()->detach($request->unite_id);

        return response()->json(['success' => true, 'message' => 'Unité retirée']);
    }

    // get unites of one formateur
    public function unites($id)
    {
        $formateur = Formateur::with(['unites.filiere', 'unites.sequences'])->findOrFail($id);
        return response()->json(['success' => true, 'data' => $formateur->unites]);
    }
}