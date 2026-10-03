<?php

namespace App\Http\Controllers\Formateur;

use App\Http\Controllers\Controller;
use App\Models\AnneeAcademique;
use App\Models\Controle;
use App\Models\Etudiant;
use App\Models\Groupe;
use App\Models\Niveau;
use App\Models\Note;
use App\Models\ScanLog;
use App\Models\ScanNameCache;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\NoteParserService;
use App\Services\PublicationLockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class ScanController extends Controller
{
    use ResolvesFormateur;

    protected $gemini;
    protected $parser;
    protected $locks;

    public function __construct(
        GeminiService $gemini,
        NoteParserService $parser,
        PublicationLockService $locks
    ) {
        $this->gemini = $gemini;
        $this->parser = $parser;
        $this->locks = $locks;
    }

    public function scan(Request $request)
    {
        // verify formateur has access to this sequence
        $controle  = Controle::findOrFail($request->controle_id);
        $sequence  = $controle->sequence;
        $formateur = $this->currentFormateur();

        $hasAccess = $formateur->sequences()->where('sequence_id', $sequence->id)->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas assigné à cette séquence',
            ], 403);
        }

        if ($this->locks->verrouillePour($controle, $request->groupe_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Les notes/bulletins ont été publiés pour ce groupe. Scan impossible.',
            ], 403);
        }

        // Get only sequences assigned to this formateur for the dropdown
        // This is handled in the frontend by filtering
        // The backend already validated access above
        // Handle both 'pdfs' array, single 'pdf', or fallback to 'images'
        $validateRules = [
            'controle_id' => 'required|exists:controles,id',
            'groupe_id'   => 'nullable|integer',
        ];
        
        if ($request->hasFile('pdfs')) {
            $validateRules['pdfs'] = 'required|array|max:10';
            $validateRules['pdfs.*'] = 'required|file|mimes:pdf|max:10240';
        } else if ($request->hasFile('pdf')) {
            $validateRules['pdf'] = 'required|file|mimes:pdf|max:10240';
        } else if ($request->hasFile('images')) {
            $validateRules['images'] = 'required|array|max:10';
            $validateRules['images.*'] = 'required|file|image|mimes:jpg,jpeg,png,webp|max:10240';
        } else {
            $validateRules['image'] = 'required|file|image|mimes:jpg,jpeg,png,webp|max:10240';
        }
        
        $request->validate($validateRules);

        $controle  = Controle::findOrFail($request->controle_id);
        $results   = [];
        $errors    = [];
        $formateur = $this->currentFormateur();

        // Matching is confined to the control's filière/année, and when the
        // formateur picked a group it is narrowed to that single group so a
        // sibling cohort's student can never absorb the grade.
        [$perimetreIds, $currentAnneeId] = $this->perimetrePourControle($controle->id);
        $groupeIds = $perimetreIds;

        if ($request->filled('groupe_id')) {
            $groupeId = (int) $request->groupe_id;

            if (!$perimetreIds->contains($groupeId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce groupe n\'appartient pas à la filière/année de ce contrôle.',
                ], 422);
            }

            $groupeIds = collect([$groupeId]);
        }

        // Handle multiple file input names
        if ($request->hasFile('pdfs')) {
            $files = $request->file('pdfs');
        } else if ($request->hasFile('pdf')) {
            $files = [$request->file('pdf')];
        } else if ($request->hasFile('images')) {
            $files = $request->file('images');
        } else {
            $files = [$request->file('image')];
        }

        foreach ($files as $file) {
            $base64    = base64_encode(file_get_contents($file->getRealPath()));
            $imagePath = $file->store('scans', 'public');
            $rawText   = $this->gemini->scanNotes($base64);

            $log = ScanLog::create([
                'formateur_id' => $formateur->id,
                'chemin_image' => $imagePath,
                'texte_brut'   => $rawText,
                'statut'       => 'en_attente',
            ]);

            $parsed = $this->parser->parseNotes($rawText);

            if (empty($parsed)) {
                $log->update(['statut' => 'echec']);
                // A null read means the AI service itself failed (missing key,
                // exhausted quota, network). That is not the same thing as an
                // unreadable PDF, and reporting it as one sent the instructor
                // off to re-scan a perfectly good sheet.
                $errors[] = [
                    'image'   => $imagePath,
                    'message' => $rawText === null
                        ? 'Le service de lecture est indisponible (clé API, quota ou réseau). Contactez l\'administrateur.'
                        : 'Impossible de lire ce fichier. Vérifiez qu\'il est bien lisible.',
                ];
                continue;
            }

            foreach ($parsed as $item) {
                $geminiNom = $item['nom'] ?? '';
                $geminiNomAr = $item['nom_ar'] ?? '';

                // A name is only ever matched against students of the scanned
                // control's filiere/annee. A name that exists somewhere else in
                // the school must NOT be linked: it belongs to a different
                // cohort, so the grade would land on the wrong record.
                $etudiant = $this->matchEtudiantDansPerimetre(
                    $geminiNom,
                    $groupeIds,
                    $currentAnneeId,
                    $item['numero_inscription'] ?? null,
                    $formateur->id
                );

                $alreadyConfirmed = $etudiant
                    ? Note::where('etudiant_id', $etudiant->id)
                        ->where('controle_id', $controle->id)
                        ->where('is_confirmed', true)
                        ->exists()
                    : false;

                $parsedValue = $this->parser->parseValue($item['note'] ?? null, (float) $controle->note_max);

                $results[] = [
                    'etudiant_id'       => $etudiant?->id,
                    'nom_prenom'        => $etudiant?->nom_prenom ?? $geminiNom,
                    'nom_ar'            => $etudiant?->nom_ar ?? $geminiNomAr,
                    'gemini_nom'        => $geminiNom,
                    'gemini_nom_ar'     => $geminiNomAr,
                    // What the sheet showed, kept apart from the resolved value so
                    // a failed lookup can be diagnosed ("no N on the sheet" is a
                    // different problem from "N that matches nobody").
                    'gemini_numero'     => is_scalar($item['numero_inscription'] ?? null)
                        ? (string) ($item['numero_inscription'] ?? '')
                        : '',
                    'numero_inscription'=> $etudiant?->numero_inscription ?? '',
                    'groupe'            => $etudiant?->groupe?->nom ?? '',
                    'groupe_id'         => $etudiant?->groupe_id,
                    'controle_id'       => $controle->id,
                    'note'              => $parsedValue['valeur'],
                    'est_absent'        => $parsedValue['est_absent'],
                    'raw_note'          => is_scalar($item['note'] ?? null) ? (string) $item['note'] : null,
                    'note_invalide'     => $parsedValue['invalid'],
                    'chemin_image'      => $imagePath,
                    'found'             => $etudiant !== null,
                    // No student is ever created from a scan. Anything that could
                    // not be matched inside the perimeter is simply "introuvable"
                    // and the admin handles it manually.
                    'introuvable'       => $etudiant === null,
                    'already_confirmed' => $alreadyConfirmed,
                ];
            }

            $log->update(['statut' => 'succes']);
        }

        // Every file failed. Saying "success" here made the frontend show
        // "vérifiez que le PDF est lisible" even when the real cause was an
        // expired key, so the failure is surfaced instead of flattened.
        if (empty($results) && !empty($errors)) {
            return response()->json([
                'success' => false,
                'message' => $errors[0]['message'],
                'data'    => ['resultats' => [], 'erreurs' => $errors],
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data'    => ['resultats' => $results, 'erreurs' => $errors],
        ]);
    }

    public function confirm(Request $request)
    {
        $request->validate([
            'notes'                  => 'required|array|max:200',
            'notes.*.etudiant_id'    => 'required|exists:etudiants,id',
            'notes.*.controle_id'    => 'required|exists:controles,id',
            'notes.*.valeur'         => 'nullable|numeric|min:0',
            'notes.*.est_absent'     => 'nullable|boolean',
            'notes.*.chemin_image'   => 'nullable|string',
            'notes.*.gemini_nom'     => 'nullable|string|max:255',
            'notes.*.gemini_nom_ar'  => 'nullable|string|max:255',
            // The cohort the sheet was scanned for. When present, every saved
            // student must belong to it, not merely to the filière/année.
            'groupe_id'             => 'nullable|integer',
        ]);

        $formateur = $this->currentFormateur();

        // The UI sends one control per sheet, but nothing enforced that, so every
        // distinct control is considered.
        $controleIds = collect($request->notes)->pluck('controle_id')->unique()->filter()->values();

        // One check for the whole payload. Rows are already forced into
        // groupe_id below, so a per-student check would repeat the same lookup.
        // Kept here as well as at scan time: a sheet can be scanned, left open,
        // and confirmed after the group has been published in between.
        foreach ($controleIds as $controleId) {
            $controle = Controle::with('sequence.unite')->find($controleId);
            if (!$controle) continue;

            if ($this->locks->verrouillePour($controle, $request->groupe_id)) {
                return response()->json(['success' => false, 'message' => 'Les notes/bulletins ont été publiés pour ce groupe. Enregistrement impossible.'], 403);
            }
        }

        // A groupe_id outside a control's filière/année would silently skip every
        // row, so it is rejected outright instead -- per control, since the
        // perimeter is derived from the control.
        if ($request->filled('groupe_id')) {
            foreach ($controleIds as $controleId) {
                [$perimetreIds] = $this->perimetrePourControle($controleId);
                if (!$perimetreIds->contains((int) $request->groupe_id)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ce groupe n\'appartient pas à la filière/année de ce contrôle.',
                    ], 422);
                }
            }
        }

        $alreadyConfirmed = [];

        foreach ($request->notes as $item) {
            $existing = Note::where('etudiant_id', $item['etudiant_id'])
                ->where('controle_id', $item['controle_id'])
                ->where('is_confirmed', true)
                ->first();

            if ($existing) {
                $etudiant = \App\Models\Etudiant::find($item['etudiant_id']);
                $alreadyConfirmed[] = [
                    'etudiant_id' => $item['etudiant_id'],
                    'controle_id' => $item['controle_id'],
                    'nom_prenom'  => $etudiant?->nom_prenom ?? 'Inconnu',
                    'note_actuelle' => $existing->est_absent ? 'absent' : $existing->valeur,
                ];
            }
        }

        // Keyed on the pair the check was built from. Keying on etudiant_id alone
        // dropped that student's legitimate rows for every other control once a
        // payload spans more than one.
        $alreadyKeys = collect($alreadyConfirmed)
            ->map(fn ($row) => $row['etudiant_id'] . ':' . $row['controle_id'])
            ->toArray();

$saved = [];
        $skipped = [];

        // All-or-nothing: a throw partway through used to leave rows 1..N-1
        // committed, and the retry then hit "already confirmed" on every one of
        // them, so the remainder could only be fixed by an admin.
        DB::transaction(function () use ($request, $formateur, $alreadyKeys, $controleIds, &$saved, &$skipped) {
            foreach ($request->notes as $item) {
                if (in_array($item['etudiant_id'] . ':' . $item['controle_id'], $alreadyKeys)) continue;

                $controle = Controle::find($item['controle_id']);
                if (!$controle) {
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'contrôle introuvable',
                    ];
                    continue;
                }

                $sequence = $controle->sequence;
                $hasAccess = $formateur->sequences()->where('sequence_id', $sequence->id)->exists();

                // Refusing silently made "20 confirmed" read as "12 confirmed", so a
                // dropped row has to be reported like any other.
                if (!$hasAccess) {
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'vous n\'êtes pas affecté à ce contrôle',
                    ];
                    continue;
                }

                // The scanned row may be linked by hand from the search box, so the
                // saved student must be re-checked against the control's own
                // filière/année here too. Without this, a hand-linked student from a
                // sibling cohort would receive the grade.
                $etudiant = Etudiant::find($item['etudiant_id']);
                if (!$etudiant) {
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'étudiant introuvable',
                    ];
                    continue;
                }

                [$perimetreIds, $currentAnneeId] = $this->perimetrePourControle($controle->id);

                $cibleIds = $perimetreIds;
                $cibleAnnee = $currentAnneeId;

                // When the scan was narrowed to one group, confirm must enforce the
                // same cohort: a hand-linked sibling would otherwise still receive
                // the grade even though the scan refused to match it.
                if ($request->filled('groupe_id')) {
                    $cibleIds = collect([(int) $request->groupe_id]);
                }

                $inPerimetre = $cibleIds->contains($etudiant->groupe_id)
                    && (!$cibleAnnee || (int) $etudiant->annee_academique_id === (int) $cibleAnnee);

                if (!$inPerimetre) {
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'étudiant hors du groupe ciblé par ce scan',
                    ];
                    continue;
                }

                $estAbsent = (bool) ($item['est_absent'] ?? false);

                // Parsed without an upper bound on purpose, so an out-of-range
                // grade can be refused instead of silently truncated. parseValue
                // clamps, which used to turn a teacher's 15 on a /10 control into
                // a stored 10 with no feedback anywhere.
                $parsed = $this->parser->parseValue($item['valeur'] ?? null, PHP_FLOAT_MAX);
                $valeur = $parsed['valeur'];

                if (!$estAbsent && $valeur !== null && $valeur > (float) $controle->note_max) {
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'note hors barème (maximum ' . $controle->note_max . ')',
                    ];
                    continue;
                }

                if (!$estAbsent && $valeur === null) {
                    // Nothing usable on the sheet. Report it instead of silently
                    // dropping the row, which would look like a successful save.
                    $skipped[] = [
                        'etudiant_id' => $item['etudiant_id'],
                        'raison'      => 'note illisible ou absente de la feuille',
                    ];
                    continue;
                }

                // Absent is stored as 0 so it counts in the averages, but flagged
                // so it is never displayed or printed as a real zero.
                if ($estAbsent) {
                    $valeur = 0.0;
                } elseif ($valeur !== null) {
                    $valeur = min($valeur, (float) $controle->note_max);
                }

                $note = Note::updateOrCreate(
                    ['etudiant_id' => $item['etudiant_id'], 'controle_id' => $item['controle_id']],
                    [
                        'valeur'        => $valeur,
                        'est_absent'    => $estAbsent,
                        'chemin_image'  => $item['chemin_image'] ?? null,
                        'is_confirmed'  => true,
                        'confirmed_at'  => now(),
                    ]
                );
                $saved[] = $note;

                // Remember the scanned name so the next sheet matches instantly.
                // Keyed per formateur: the name comes from the AI reading the sheet
                // and is accepted verbatim from the client, so a shared entry would
                // let one formateur redirect another instructor's scan.
                if (!empty($item['gemini_nom'])) {
                    ScanNameCache::updateOrCreate(
                        [
                            'formateur_id' => $formateur->id,
                            'raw_nom'      => $item['gemini_nom'],
                            'raw_nom_ar'   => $item['gemini_nom_ar'] ?? '',
                        ],
                        [
                            'raw_nom_normalized' => ScanNameCache::normalizeName($item['gemini_nom']),
                            'etudiant_id'         => $item['etudiant_id'],
                        ]
                    );
                }
            }
        }); // end DB::transaction

        if (count($alreadyConfirmed) > 0 && count($saved) === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Tous les étudiants ont déjà des notes confirmées pour ce contrôle. Contactez l\'administrateur pour les modifier.',
                'already_confirmed' => $alreadyConfirmed,
                'data' => [],
            ], 409);
        }

        $msg = count($saved) . ' note(s) confirmée(s)';
        if (count($alreadyConfirmed) > 0) {
            $msg .= '. ' . count($alreadyConfirmed) . ' étudiant(s) déjà confirmé(s) ignoré(s) — contactez l\'administrateur pour les modifier.';
        }
        if (count($skipped) > 0) {
            // Report the actual reasons instead of a fixed wording, since a row
            // can be refused either for an unreadable grade or for being outside
            // the group targeted by the scan.
            $motifs = collect($skipped)->countBy(fn ($s) => $s['raison'] ?? 'raison inconnue');
            $detail = $motifs->map(fn ($n, $raison) => $n . ' (' . $raison . ')')->implode(', ');
            $msg .= '. ' . count($skipped) . ' ligne(s) non enregistrée(s) : ' . $detail . '.';
        }

        return response()->json([
            'success' => true,
            'data'    => $saved,
            'skipped' => $skipped,
            'already_confirmed' => $alreadyConfirmed,
            'message' => $msg,
        ]);
    }

    /**
     * Groupes of the control's fili�re/ann�e plus the current academic year,
     * i.e. the only students a scanned sheet may legitimately be matched to.
     *
     * @return array{0:\Illuminate\Support\Collection,1:int|null}
     */
    private function perimetrePourControle(int $controleId): array
    {
        $controle = Controle::with('sequence.unite')->find($controleId);
        $unite = $controle?->sequence?->unite;
        if (!$unite) return [collect(), null];

        $niveau = Niveau::where('filiere_id', $unite->filiere_id)
            ->where('numero', $unite->numero_annee)
            ->first();
        if (!$niveau) return [collect(), null];

        $currentAnneeId = AnneeAcademique::where('is_current', true)->first()?->id;
        if (!$currentAnneeId) return [collect(), null];

        $groupeIds = Groupe::where('niveau_id', $niveau->id)
            ->where('annee_academique_id', $currentAnneeId)
            ->orderBy('nom')
            ->pluck('id');

        return [$groupeIds, $currentAnneeId];
    }

    /**
     * Find the student a scanned name belongs to, strictly inside the perimeter.
     *
     * Strategies, from strictest to loosest:
     *   0. the registration number printed on the sheet, when Gemini read it
     *   1. the remembered scan-name cache
     *   3. every word present (handles a reversed name)
     *   4. first word + last word (handles middle-name variations)
     *
     * Every strategy is restricted to $groupeIds. When more than one student
     * matches, the row stays "introuvable" rather than guessing: a wrong guess
     * would write the grade onto the wrong person.
     */
    private function matchEtudiantDansPerimetre(
        string $geminiNom,
        $groupeIds,
        ?int $currentAnneeId,
        ?string $numeroScanne = null,
        ?int $formateurId = null
    ): ?Etudiant {
        $searchName = trim($geminiNom);
        $numeroScanne = $numeroScanne !== null ? trim($numeroScanne) : '';

        if (!is_countable($groupeIds) || count($groupeIds) === 0) {
            return null;
        }
        // A row is only worth matching when we have at least one identifier.
        if ($searchName === '' && $numeroScanne === '') {
            return null;
        }

        $inPerimetre = fn ($q) => $q->whereIn('groupe_id', $groupeIds)
            ->when($currentAnneeId, fn ($q2) => $q2->where('annee_academique_id', $currentAnneeId));

        // 0. the registration number printed on the sheet. Strictest check, so
        // it runs first; still bounded by the perimeter.
        if ($numeroScanne !== '') {
            $parNumero = $inPerimetre(Etudiant::with('groupe'))
                ->whereRaw('REPLACE(numero_inscription, " ", "") = ?', [
                    str_replace(' ', '', $numeroScanne),
                ])
                ->get();

            if ($parNumero->count() === 1) {
                return $parNumero->first();
            }
            // Several students sharing the number, or none: fall through to the
            // name rather than guessing on an unreliable read.
        }

        if ($searchName === '') {
            return null;
        }

        // 1. remembered mapping from an earlier scan, for this instructor only.
        // A null formateur (only reachable from tests) is treated as "no
        // instructor owns this row" rather than as "every instructor", so the
        // cache can never widen across formateurs.
        $normalized = ScanNameCache::normalizeName($geminiNom);
        if ($normalized !== '') {
            $cache = ScanNameCache::where('raw_nom_normalized', $normalized)
                ->when(
                    $formateurId,
                    fn ($q) => $q->where('formateur_id', $formateurId),
                    fn ($q) => $q->whereNull('formateur_id')
                )
                ->first();
            if ($cache) {
                $cached = $inPerimetre(Etudiant::with('groupe'))->find($cache->etudiant_id);
                if ($cached) {
                    return $cached;
                }
            }
        }

        $safeName = str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($searchName));
        $safeName = preg_replace('/\s+/', ' ', $safeName) ?: $safeName;
        $words = array_values(array_filter(explode(' ', $safeName), fn ($w) => mb_strlen($w) > 1));

        // 2. full name as a substring
        $exact = $inPerimetre(Etudiant::with('groupe'))
            ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $safeName . '%'])
            ->get();
        if ($exact->count() === 1) {
            return $exact->first();
        }
        if ($exact->count() > 1) {
            return null; // ambiguous: better "introuvable" than a wrong student
        }

        // 3. all words present, in any order
        if (count($words) >= 2) {
            $q = $inPerimetre(Etudiant::with('groupe'));
            foreach ($words as $w) {
                $q->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $w . '%']);
            }
            $all = $q->get();
            if ($all->count() === 1) {
                return $all->first();
            }
        }

        // 4. first word + last word only
        if (count($words) >= 2) {
            $first = $words[0];
            $last = $words[count($words) - 1];
            $partial = $inPerimetre(Etudiant::with('groupe'))
                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $first . '%'])
                ->whereRaw('LOWER(nom_prenom) LIKE ?', ['%' . $last . '%'])
                ->get();
            if ($partial->count() === 1) {
                return $partial->first();
            }
        }

        return null;
    }
}
