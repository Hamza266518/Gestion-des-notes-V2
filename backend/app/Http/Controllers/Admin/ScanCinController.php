<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\GeminiTransportException;
use App\Models\Etudiant;
use App\Models\User;
use App\Services\CinScanNormalizer;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\NumeroInscriptionService;
use App\Traits\ValidatesScanUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ScanCinController extends Controller
{
    use ValidatesScanUploads;
    protected $gemini;
    protected $parser;
    protected $numeroService;
    protected $normalizer;

    public function __construct(
        GeminiService $gemini,
        NoteParserService $parser,
        NumeroInscriptionService $numeroService,
        CinScanNormalizer $normalizer
    ) {
        $this->gemini        = $gemini;
        $this->parser        = $parser;
        $this->numeroService = $numeroService;
        $this->normalizer    = $normalizer;
    }

    public function scan(Request $request)
    {
        try {
            $request->validate([
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'filiere_code'        => 'required|string',
                'mode'                => 'nullable|string|in:create,sync',
            ]);

            if ($request->hasFile('fichiers')) {
                $pdfs = $request->file('fichiers');
            } else if ($request->hasFile('pdfs')) {
                $pdfs = $request->file('pdfs');
            } else if ($request->hasFile('pdf')) {
                $pdfs = [$request->file('pdf')];
            } else if ($request->hasFile('images')) {
                $pdfs = $request->file('images');
            } else if ($request->hasFile('image')) {
                $pdfs = [$request->file('image')];
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun fichier envoyé',
                ], 422);
            }

            if (!is_array($pdfs)) {
                $pdfs = [$pdfs];
            }

            $validated = $this->validateScanUploads($pdfs);
            if ($validated !== null) {
                return $validated;
            }

            $results = [];
            $errors  = [];
            $mode    = $request->input('mode', 'create');

            foreach ($pdfs as $index => $pdf) {
                $base64 = base64_encode(file_get_contents($pdf->getRealPath()));
                $mime   = $pdf->getMimeType() ?: 'application/octet-stream';

                try {
                    $rawText = $this->gemini->scanCin($base64, $mime);
                } catch (GeminiTransportException $e) {
                    \Log::warning('Gemini CIN transport failure', ['code' => $e->getCode()]);
                    return response()->json([
                        'success' => false,
                        'message' => $e->getCode() === 429
                            ? "Toutes les clés Gemini ont atteint leur limite d'utilisation (429). Réessayez dans un moment, ou ajoutez une clé avec un quota disponible."
                            : ($e->getCode()
                                ? "L'API Gemini est surchargée (erreur {$e->getCode()}). Réessayez le scan dans quelques instants."
                                : "Impossible de joindre l'API Gemini (connexion interrompue ou délai dépassé). Vérifiez votre connexion Internet et réessayez."),
                    ], 502);
                }

                $students = $this->parser->parseCin($rawText);

                if (empty($students)) {
                    $errors[] = ['message' => 'Document illisible'];
                    \Log::warning('ScanCin: no students parsed', ['file_index' => $index]);
                    continue;
                }

                foreach ($students as $student) {
                    $data = $this->normalizer->normalize($student);

                    if (empty($data['nom_prenom'])) {
                        $errors[] = ['message' => 'Nom manquant pour un étudiant'];
                        continue;
                    }

                    if (empty($data['cin'])) {
                        $errors[] = ['message' => 'CIN illisible pour ' . $data['nom_prenom']];
                        continue;
                    }

                    $results[] = array_merge($data, [
                        'groupe_id'           => $request->groupe_id,
                        'annee_academique_id' => $request->annee_academique_id,
                    ]);
                }
            }

            // Match against existing students by CIN. In "sync" mode the full
            // existing record is returned so the admin can review side-by-side
            // which fields differ and apply the corrections.
            $cins = array_filter(array_column($results, 'cin'));
            $existingByCin = [];
            if (!empty($cins)) {
                $existingByCin = Etudiant::whereIn('cin', $cins)
                    ->get([
                        'id', 'nom_prenom', 'nom_ar', 'cin', 'cin_ar',
                        'date_naissance', 'date_naissance_ar',
                        'lieu_naissance', 'lieu_naissance_ar',
                        'nationalite', 'nationalite_ar',
                        'date_inscription', 'date_inscription_ar',
                        'numero_inscription', 'numero_inscription_ar',
                    ])
                    ->keyBy('cin')
                    ->all();
            }

            // New students of this batch are numbered 1..N: the number is derived
            // from the current row count, which does not move between iterations,
            // so an offset reserves each following slot.
            $batchOffset = 0;

            foreach ($results as &$r) {
                $cin      = $r['cin'] ?? '';
                $existing = $cin !== '' ? ($existingByCin[$cin] ?? null) : null;

                if ($existing) {
                    // A re-scan never renumbers an existing student.
                    $r['numero_inscription']   = $existing->numero_inscription;
                    $r['numero_inscription_ar'] = $existing->numero_inscription_ar;
                    $r['existing']         = true;
                    $r['existing_student'] = $existing->nom_prenom;
                    $r['existing_numero']  = $existing->numero_inscription;
                    $r['found']            = true;
                    $r['etudiant_id']      = $existing->id;
                    $r['existing_data']    = [
                        'nom_prenom'            => $existing->nom_prenom,
                        'nom_ar'                => $existing->nom_ar,
                        'cin'                   => $existing->cin,
                        'cin_ar'                => $existing->cin_ar,
                        'date_naissance'        => $existing->date_naissance,
                        'date_naissance_ar'     => $existing->date_naissance_ar,
                        'lieu_naissance'        => $existing->lieu_naissance,
                        'lieu_naissance_ar'     => $existing->lieu_naissance_ar,
                        'nationalite'           => $existing->nationalite,
                        'nationalite_ar'        => $existing->nationalite_ar,
                        'date_inscription'      => $existing->date_inscription,
                        'date_inscription_ar'   => $existing->date_inscription_ar,
                        'numero_inscription'    => $existing->numero_inscription,
                        'numero_inscription_ar' => $existing->numero_inscription_ar,
                    ];
                } elseif ($mode === 'sync') {
                    // Nothing to apply for a CIN that does not exist yet.
                    $r['numero_inscription']   = null;
                    $r['numero_inscription_ar'] = null;
                    $r['found']            = false;
                    $r['etudiant_id']      = null;
                    $r['existing_data']    = null;
                } else {
                    $r['numero_inscription'] = $this->numeroService->generate(
                        $request->filiere_code,
                        $request->annee_academique_id,
                        $batchOffset
                    );
                    $batchOffset++;
                    $r['numero_inscription_ar'] = $r['numero_inscription'];
                    $r['found']            = false;
                    $r['etudiant_id']      = null;
                    $r['existing_data']    = null;
                }
            }
            unset($r);

            return response()->json([
                'success' => true,
                'data'    => ['resultats' => $results, 'erreurs' => $errors],
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erreur scan CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du scan CIN: ' . $e->getMessage()], 500);
        }
    }

    public function confirm(Request $request)
    {
        try {
            $request->validate([
                'stagiaires'                            => 'required|array',
                'stagiaires.*.nom_prenom'               => 'required|string',
                'stagiaires.*.nom_ar'                   => 'nullable|string',
                'stagiaires.*.cin'                      => 'required|string',
                'stagiaires.*.date_naissance'           => 'nullable|date',
                'stagiaires.*.numero_inscription'       => 'required|string',
                'stagiaires.*.groupe_id'                => 'required|exists:groupes,id',
                'stagiaires.*.annee_academique_id'      => 'required|exists:annees_academiques,id',
                'stagiaires.*.date_naissance_ar'        => 'nullable|string',
                'stagiaires.*.lieu_naissance'           => 'nullable|string',
                'stagiaires.*.lieu_naissance_ar'        => 'nullable|string',
                'stagiaires.*.nationalite'              => 'nullable|string',
                'stagiaires.*.nationalite_ar'           => 'nullable|string',
                'stagiaires.*.date_inscription'         => 'nullable|string',
                'stagiaires.*.cin_ar'                   => 'nullable|string',
                'stagiaires.*.numero_inscription_ar'    => 'nullable|string',
                'stagiaires.*.date_inscription_ar'      => 'nullable|string',
            ]);

            $created = 0;
            $skipped = 0;

            // All-or-nothing: a failure partway through used to leave the first
            // students committed while the response reported an error, so the
            // admin could neither see nor retry the remainder cleanly.
            DB::transaction(function () use ($request, &$created, &$skipped) {
                foreach ($request->stagiaires as $item) {
                    $cin = $this->normalizer->normalizeCin($item['cin']);

                    if ($cin === '') {
                        $skipped++;
                        continue;
                    }

                    if (Etudiant::where('cin', $cin)->exists()) {
                        $skipped++;
                        continue;
                    }

                    $password = User::generateStrongPassword();

                    $user = User::create([
                        'name'               => $item['nom_prenom'],
                        'username'           => app(\App\Services\UsernameGenerator::class)
                            ->reserveFor((string) $item['nom_prenom'], 'etudiant'),
                        'password'           => Hash::make($password),
                        'password_encrypted' => Crypt::encryptString($password),
                        'password_original_encrypted' => Crypt::encryptString($password),
                        'role'               => 'etudiant',
                    ]);

                    $data = $this->normalizer->normalize(array_merge($item, ['cin' => $cin]));

                    Etudiant::create([
                        'user_id'             => $user->id,
                        'groupe_id'           => $item['groupe_id'],
                        'annee_academique_id' => $item['annee_academique_id'],
                        'numero_inscription'  => $item['numero_inscription'],
                        'cin'                 => $cin,
                        'nom_ar'              => $data['nom_ar'] ?: null,
                        'date_naissance'      => $data['date_naissance'],
                        'date_naissance_ar'   => $data['date_naissance_ar'] ?: null,
                        'lieu_naissance'      => $data['lieu_naissance'] ?: null,
                        'lieu_naissance_ar'   => $data['lieu_naissance_ar'] ?: null,
                        'nationalite'         => $data['nationalite'],
                        'nationalite_ar'      => $data['nationalite_ar'] ?: null,
                        'date_inscription'    => $data['date_inscription'],
                        'date_inscription_ar' => $data['date_inscription_ar'] ?: null,
                        'cin_ar'              => $data['cin_ar'] ?: null,
                        'numero_inscription_ar' => $data['numero_inscription_ar'] ?: null,
                    ]);

                    $created++;
                }
            });

            $parts = [];
            if ($created > 0) $parts[] = "{$created} créé(s)";
            if ($skipped > 0) $parts[] = "{$skipped} ignoré(s) (déjà existant)";

            return response()->json([
                'success' => true,
                'data'    => ['crees' => $created, 'ignores' => $skipped],
                'message' => 'Stagiaires enregistrés. ' . implode(', ', $parts),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erreur confirmation scan CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'enregistrement des stagiaires'], 500);
        }
    }

    public function apply(Request $request)
    {
        try {
            $request->validate([
                'stagiaires'                     => 'required|array',
                'stagiaires.*.etudiant_id'       => 'required|integer',
                'stagiaires.*.nom_prenom'        => 'nullable|string',
                'stagiaires.*.nom_ar'            => 'nullable|string',
                'stagiaires.*.cin'               => 'nullable|string',
                'stagiaires.*.cin_ar'            => 'nullable|string',
                'stagiaires.*.date_naissance'    => 'nullable|date',
                'stagiaires.*.date_naissance_ar' => 'nullable|string',
                'stagiaires.*.lieu_naissance'    => 'nullable|string',
                'stagiaires.*.lieu_naissance_ar' => 'nullable|string',
                'stagiaires.*.nationalite'       => 'nullable|string',
                'stagiaires.*.nationalite_ar'    => 'nullable|string',
            ]);

            $updated      = 0;
            $cinDiff      = 0;
            $introuvables = 0;

            DB::transaction(function () use ($request, &$updated, &$cinDiff, &$introuvables) {
                foreach ($request->stagiaires as $item) {
                    $etudiant = Etudiant::find($item['etudiant_id']);
                    if (!$etudiant) {
                        $introuvables++;
                        continue;
                    }

                    $scannedCin  = $this->normalizer->normalizeCin((string) ($item['cin'] ?? ''));
                    $existingCin = $etudiant->cin ? $this->normalizer->normalizeCin($etudiant->cin) : '';

                    // Identity is never rewritten by a re-scan: if the student already
                    // has a CIN it must match the scanned one, otherwise the row is
                    // surfaced as red in the diff and skipped.
                    if ($existingCin !== '' && $scannedCin !== '' && $existingCin !== $scannedCin) {
                        $cinDiff++;
                        continue;
                    }

                    $data = $this->normalizer->normalize(array_merge(
                        $item,
                        ['cin' => $scannedCin !== '' ? $scannedCin : $existingCin]
                    ));

                    $update = [];
                    // Only non-empty, readable scanned values overwrite the existing
                    // record; an empty OCR field always keeps the current value.
                    foreach (['nom_prenom', 'nom_ar', 'date_naissance', 'date_naissance_ar',
                              'lieu_naissance', 'lieu_naissance_ar', 'nationalite', 'nationalite_ar'] as $field) {
                        $raw = (string) ($item[$field] ?? '');
                        $val = $data[$field] ?? null;
                        if ($raw !== '' && $val !== null && (string) $val !== '') {
                            $update[$field] = $val;
                        }
                    }

                    // A missing CIN is filled in; otherwise the CIN stays and we keep
                    // the Arabic mirror in step with it.
                    if ($existingCin === '' && $scannedCin !== '') {
                        $update['cin']    = $scannedCin;
                        $update['cin_ar'] = $scannedCin;
                    } else {
                        $update['cin_ar'] = $existingCin ?: $etudiant->cin;
                    }

                    if (!empty($update)) {
                        $etudiant->update($update);
                        if (!empty($update['nom_prenom']) && $etudiant->user) {
                            $etudiant->user->update(['name' => $update['nom_prenom']]);
                        }
                    }

                    $updated++;
                }
            });

            $parts = [];
            if ($updated > 0) $parts[] = "{$updated} mis à jour";
            if ($cinDiff > 0) $parts[] = "{$cinDiff} CIN ne correspond pas (ignoré)";
            if ($introuvables > 0) $parts[] = "{$introuvables} introuvable(s)";

            return response()->json([
                'success' => true,
                'data'    => ['maj' => $updated, 'cin_diff' => $cinDiff, 'introuvables' => $introuvables],
                'message' => 'Corrections appliquées. ' . implode(', ', $parts),
            ]);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Erreur application scan CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors de l\'application des corrections'], 500);
        }
    }

    public function checkExisting(Request $request): JsonResponse
    {
        try {
            $cins = $request->input('cins', []);
            if (!is_array($cins) || empty($cins)) {
                return response()->json(['success' => true, 'data' => []]);
            }

            $existing = Etudiant::whereIn('cin', $cins)
                ->get(['cin', 'nom_prenom', 'numero_inscription'])
                ->keyBy('cin');

            return response()->json([
                'success' => true,
                'data' => $existing,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur checkExisting CIN: ' . $e->getMessage());
            return response()->json(['success' => false, 'data' => []], 500);
        }
    }
}