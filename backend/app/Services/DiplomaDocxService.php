<?php

namespace App\Services;

use App\Models\Diplome;
use App\Models\DiplomeParametre;
use Carbon\Carbon;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive as PhpWordZipArchive;
use PhpOffice\PhpWord\SimpleType\Jc;

class DiplomaDocxService
{
    private CitiesTranslator $cities;

    /**
     * True when the current diploma's filière is listed in
     * config('diplomes.font_ar_filiere_codes'): its Arabic filière name is
     * then printed in config('diplomes.font_ar_filiere') instead of font_ar.
     * Set by buildPhpWord() for each document, so it never leaks between two
     * diplomas built in the same request (batch downloads).
     */
    private bool $dedicatedFiliereFont = false;

    public function __construct(?CitiesTranslator $cities = null)
    {
        $this->cities = $cities ?? new CitiesTranslator();
    }

    /**
     * The values printed on the diploma, keyed by field key. Mirrors the payload
     * the old JavaScript renderer received (minus moyenne/mention, which are
     * intentionally not printed on the document).
     *
     * The Arabic side NEVER falls back to Latin text: empty Arabic values are
     * derived (Moroccan city dictionaries, Arabic month names) or left blank,
     * so a diploma never shows "MAROCAINE" or "Casablanca" on its Arabic half.
     */
    private function diplomaTypeFromFiliere($filiere): string
    {
        if (!$filiere) {
            return config('diplomes.default_type');
        }
        $section = strtoupper(trim((string) ($filiere->section ?? '')));
        if (in_array($section, ['Q', 'QUALIFICATION'], true)) {
            return 'Qualification';
        }
        if (in_array($section, ['T', 'TECHNICIEN'], true)) {
            return 'Technicien';
        }
        if (in_array($section, ['TS', 'SPECIALISATION', 'TECHNICIEN SPECIALISE'], true)) {
            return 'Specialisation';
        }
        // fallback to old if section not set
        $type = $filiere->type_formation ?? null;
        if ($type) {
            return $type;
        }
        return config('diplomes.default_type');
    }

    public function dataFor(Diplome $diplome): array
    {
        $etudiant = $diplome->etudiant;
        $filiere = $etudiant->groupe?->niveau?->filiere;
        $groupe = $etudiant->groupe;
        $normalizer = new CinScanNormalizer();

        $typeFormation = $this->diplomaTypeFromFiliere($filiere);
        $typeFormationAr = $filiere?->type_formation_ar ?? match ($typeFormation) {
            'Qualification' => 'تأهيل',
            'Specialisation' => 'تخصص',
            default => 'تقني',
        };

        return [
            'nom_prenom'            => $etudiant->nom_prenom ?? '',
            'type_formation'        => $typeFormation,
            'date_naissance'        => $this->frDate($etudiant->date_naissance),
            'lieu_naissance'        => mb_strtoupper((string) ($etudiant->lieu_naissance ?? ''), 'UTF-8'),
            'cin'                   => $etudiant->cin ?? '',
            'nationalite'           => $etudiant->nationalite ?? 'Marocaine',
            'numero_inscription'    => $etudiant->numero_inscription ?? '',
            'date_inscription'      => $this->frDate($etudiant->date_inscription),
            'filiere'               => $filiere?->nom ?? '',
            'promotion'             => $groupe?->promotion ?? '',
            'date_delivrance'       => now()->format('d/m/Y'),
            'nom_ar'                => $etudiant->nom_ar ?? '',
            // Birth and inscription dates must be numeric (dd/mm/yyyy) on both
            // halves, same face/size as French. Never use stored Arabic month
            // forms.
            'date_naissance_ar'     => $this->frDate($etudiant->date_naissance),
            'lieu_naissance_ar'     => $etudiant->lieu_naissance_ar ?: $this->cities->translate($etudiant->lieu_naissance),
            'cin_ar'                => $etudiant->cin_ar ?? ($etudiant->cin ?? ''),
            'nationalite_ar'        => $etudiant->nationalite_ar ?: $this->nationaliteAr($etudiant->nationalite),
            'numero_inscription_ar' => $etudiant->numero_inscription ?? '',
            'date_inscription_ar'   => $this->frDate($etudiant->date_inscription),
            'filiere_ar'            => $filiere?->nom_ar ?? '',
            'type_formation_ar'     => $typeFormationAr,
        ];
    }

    private function nationaliteAr(?string $nationalite): string
    {
        $value = mb_strtolower((string) $nationalite, 'UTF-8');

        if (preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
            return str_contains(str_replace(['الأ', 'إ', 'آ'], 'ا', $value), 'مغرب') ? 'مغربية' : '';
        }

        $folded = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
        $folded = preg_replace('/[^a-z0-9]/', '', $folded) ?? $folded;

        return (str_contains($folded, 'maroc') || str_contains($folded, 'moroc')) ? 'مغربية' : '';
    }

    /**
     * The establishment-level values (same for every diploma), keyed by
     * config('diplomes.infos').*. Stored globally in diplome_parametres; a
     * missing row or missing key yields an empty string (nothing is printed).
     */
    public function informations(): array
    {
        $valeurs = $this->storedParametres()->valeurs ?? [];

        $out = [];
        foreach (config('diplomes.infos', []) as $info) {
            $key = $info['key'];
            $out[$key] = trim((string) ($valeurs[$key] ?? ''));
        }

        return $out;
    }

    /**
     * Marker coordinates for the information fields on one template type,
     * keyed [infoKey][group] = ['x','y','size']. Config holds the defaults; the
     * calibrated values saved from the UI override them per type.
     */
    public function infoPositions(string $type): array
    {
        $positions = [];
        foreach (config('diplomes.infos', []) as $info) {
            // A fact is only drawn on the halves it declares, so a fact with no
            // 'ar' marker is printed on the French half only.
            foreach (['fr', 'ar'] as $group) {
                if (!isset($info[$group])) {
                    continue;
                }
                $default = $info[$group];
                $positions[$info['key']][$group] = [
                    'x'    => (float) $default['x'],
                    'y'    => (float) $default['y'],
                    'size' => (int) ($default['size'] ?? 10),
                ];
            }
        }

        $stored = ($this->storedParametres()->positions ?? [])[$type] ?? [];
        foreach ($stored as $key => $groups) {
            if (!isset($positions[$key]) || !is_array($groups)) {
                continue;
            }
            foreach (['fr', 'ar'] as $group) {
                // Never resurrect a group the config no longer declares.
                if (!isset($positions[$key][$group])) {
                    continue;
                }
                if (isset($groups[$group]['x'], $groups[$group]['y'])) {
                    $positions[$key][$group]['x'] = $this->clampFraction($groups[$group]['x']);
                    $positions[$key][$group]['y'] = $this->clampFraction($groups[$group]['y']);
                }
            }
        }

        return $positions;
    }

    /** Calibrated (or default) information positions for every template type. */
    public function allInfoPositions(): array
    {
        $out = [];
        foreach (array_keys(config('diplomes.templates', [])) as $type) {
            $out[$type] = $this->infoPositions($type);
        }

        return $out;
    }

    /**
     * Persist the information values. Only the keys declared in config are
     * accepted, so an unexpected payload cannot bloat the row.
     */
    public function saveInformations(array $valeurs): void
    {
        $allowed = array_column(config('diplomes.infos', []), 'key');
        $clean = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $valeurs)) {
                $clean[$key] = trim((string) $valeurs[$key]);
            }
        }

        $row = $this->storedParametres();
        $row->valeurs = array_merge($row->valeurs ?? [], $clean);
        $row->save();
    }

    /**
     * Persist calibrated information positions. Accepts the full map
     * [type => [infoKey => [group => ['x','y']]]] but only keeps known types,
     * info keys and groups, with coordinates clamped to the page.
     */
    public function saveInfoPositions(array $positionsByType): void
    {
        $types = array_keys(config('diplomes.templates', []));
        $infos = array_column(config('diplomes.infos', []), 'key');

        $row = $this->storedParametres();
        $stored = $row->positions ?? [];

        foreach ($positionsByType as $type => $positions) {
            if (!in_array($type, $types, true) || !is_array($positions)) {
                continue;
            }
            foreach ($positions as $key => $groups) {
                if (!in_array($key, $infos, true) || !is_array($groups)) {
                    continue;
                }
                foreach (['fr', 'ar'] as $group) {
                    if (isset($groups[$group]['x'], $groups[$group]['y'])) {
                        $stored[$type][$key][$group] = [
                            'x' => $this->clampFraction($groups[$group]['x']),
                            'y' => $this->clampFraction($groups[$group]['y']),
                        ];
                    }
                }
            }
        }

        $row->positions = $stored;
        $row->save();
    }

    private function storedParametres(): DiplomeParametre
    {
        try {
            return DiplomeParametre::current();
        } catch (\Throwable $e) {
            // The table may not exist yet (fresh install before migrating): fall
            // back to an unsaved, empty instance so the docx still builds.
            return new DiplomeParametre();
        }
    }

    private function clampFraction($value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }

    /**
     * Build the editable .docx in a temp file and return its path. Callers are
     * responsible for cleaning it up (or use deleteFileAfterSend).
     */
    public function build(Diplome $diplome): string
    {
        $phpWord = $this->buildPhpWord($diplome);

        // Pure-PHP fallback when ext-zip is not loaded on the server.
        if (!class_exists('ZipArchive')) {
            Settings::setZipClass(Settings::PCLZIP);
        }

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $tmp = tempnam(sys_get_temp_dir(), 'diplome_');
        $writer->save($tmp);

        // Word remembers the last view it used for the .docx type and reopens
        // new files in it; in Draft ("Brouillon") or Web layout every floating
        // object -- the template background AND the text boxes -- is hidden, so
        // the diploma looks blank. Pin the document to Print layout so this can
        // not happen, and make the field text boxes transparent so the template
        // artwork stays visible behind the values.
        $fixed = $this->postProcess($tmp);
        @unlink($tmp);

        return $fixed;
    }

    /**
     * Rewrite the parts PhpWord cannot express through its API: print view in
     * settings.xml and a borderless, transparent fill on the field text boxes
     * of document.xml.
     */
    private function postProcess(string $docxPath): string
    {
        $out = tempnam(sys_get_temp_dir(), 'diplome_v_');
        // PCLZip refuses to create an archive over the existing empty tempnam
        // file (PCLZIP_ERR_BAD_FORMAT), so remove it first -- exactly what
        // PhpWord's AbstractWriter::getZipArchive() does.
        @unlink($out);

        $src = new PhpWordZipArchive();
        $src->open($docxPath);

        $dst = new PhpWordZipArchive();
        $dst->open($out, PhpWordZipArchive::OVERWRITE);

        for ($i = 0; $i < $src->numFiles; $i++) {
            $name = $src->getNameIndex($i);
            $contents = (string) $src->getFromName($name);
            if ($name === 'word/settings.xml') {
                $contents = $this->injectPrintView($contents);
            } elseif ($name === 'word/document.xml') {
                $contents = $this->collapseShapeParagraphs(
                    $this->injectTransparentTextboxes($contents)
                );
            }
            $dst->addFromString($name, $contents);
        }

        $src->close();
        $dst->close();

        return $out;
    }

    /**
     * Add <w:view w:val="print"/> as the first child of the settings element.
     * Kept public so the XML transformation can be unit-tested without a zip.
     */
    public function injectPrintView(string $settingsXml): string
    {
        if (strpos($settingsXml, '<w:view ') !== false) {
            return $settingsXml;
        }

        return (string) (preg_replace(
            '/(<w:settings[^>]*>)/',
            '$1<w:view w:val="print"/>',
            $settingsXml,
            1
        ) ?? $settingsXml);
    }

    /**
     * PhpWord emits every field text box as <v:shape type="#_x0000_t0202">
     * without fill/stroke declarations, and VML defaults `filled` and `stroked`
     * to true -- so each value paints an opaque rectangle (and a hairline
     * border) over the template raster. Force `filled="f"` (transparent fill)
     * and `stroked="f"` (no border) on those shapes so only the bare text sits
     * on the diploma artwork. Idempotent.
     */
    public function injectTransparentTextboxes(string $documentXml): string
    {
        return (string) preg_replace_callback(
            '/<v:shape\b[^>]*>/',
            static function (array $match): string {
                $tag = $match[0];
                if (strpos($tag, '#_x0000_t0202') === false) {
                    return $tag;
                }
                foreach (['filled', 'stroked'] as $attribute) {
                    if (!preg_match('/\b' . $attribute . '=/', $tag)) {
                        $tag = str_replace('<v:shape', '<v:shape ' . $attribute . '="f"', $tag);
                    }
                }

                return $tag;
            },
            $documentXml
        );
    }

    /**
     * PhpWord gives every floating object -- the template raster and each value
     * text box -- its own <w:p> that holds nothing but the shape. Word still
     * lays those paragraphs out as one empty text line each, so a diploma with
     * ~50 boxes adds ~50 lines to the flow, overruns the 595pt page and gets a
     * silent second sheet. Force each such paragraph to a 1pt exact line (its
     * shape is page-anchored, so the position is untouched) and the diploma
     * stays on a single page. Idempotent.
     */
    public function collapseShapeParagraphs(string $documentXml): string
    {
        $collapsed = '<w:pPr>'
            . '<w:spacing w:before="0" w:after="0" w:line="20" w:lineRule="exact"/>'
            . '<w:rPr><w:sz w:val="2"/><w:szCs w:val="2"/></w:rPr>'
            . '</w:pPr>';

        return (string) preg_replace(
            '/<w:p>(?:<w:pPr\/>)?(<w:r><w:pict>)/',
            '<w:p>' . $collapsed . '$1',
            $documentXml
        );
    }

    /**
     * Assemble the PhpWord document (section, background template, text boxes)
     * without writing it to disk. Exposed so tests can assert the produced
     * section/page geometry.
     */
    public function buildPhpWord(Diplome $diplome): PhpWord
    {
        $filiere = $diplome->etudiant->groupe?->niveau?->filiere;
        $type = $this->diplomaTypeFromFiliere($filiere) ?? $filiere?->type_formation_effective ?? config('diplomes.default_type');

        if (!isset(config('diplomes.templates')[$type])) {
            $type = config('diplomes.default_type');
        }

        // The Arabic filière name wears a face of its own only for the
        // filières listed in config('diplomes.font_ar_filiere_codes') — today
        // IP (Infirmier Polyvalent). Every other filière keeps font_ar on its
        // Arabic half; the size is never touched.
        $this->dedicatedFiliereFont = in_array(
            (string) ($diplome->etudiant->groupe?->niveau?->filiere?->code ?? ''),
            (array) config('diplomes.font_ar_filiere_codes', []),
            true
        );

        $pageW = (float) config('diplomes.page_width_pt');
        $pageH = (float) config('diplomes.page_height_pt');
        $boxW = (float) config('diplomes.box_width_pt');
        $colors = config('diplomes.colors');

        $phpWord = new PhpWord();
        // paperSize BEFORE orientation: setOrientation() swaps the long/short
        // sides, so paperSize must already have populated them (setting the size
        // afterwards would wipe the swap and emit a portrait-sized page that Word
        // clips every field past the 210mm logical width).
        $section = $phpWord->addSection([
            'paperSize' => 'A4',
            'orientation' => 'landscape',
            'marginTop' => 0,
            'marginBottom' => 0,
            'marginLeft' => 0,
            'marginRight' => 0,
            // There is no header/footer text, but Word still enforces the
            // default 720-twip (0.5") header/footer distance and silently grows
            // an otherwise zero top/bottom margin to fit it -- which eats page
            // height the floating text boxes need. Pin both to zero.
            'headerHeight' => 0,
            'footerHeight' => 0,
        ]);

        // Full-page raster of the original template, anchored to the page corner
        // and pushed behind the text boxes.
        $template = public_path(ltrim((string) config('diplomes.templates.' . $type), '/'));
        $section->addImage($template, [
            'width' => $pageW,
            'height' => $pageH,
            'positioning' => 'absolute',
            'posHorizontal' => 'absolute',
            'posVertical' => 'absolute',
            'hPos' => 'absolute',
            'vPos' => 'absolute',
            'posHorizontalRel' => 'page',
            'posVerticalRel' => 'page',
            'marginLeft' => 0,
            'marginTop' => 0,
            'wrap' => 'behind',
        ]);

        $data = $this->dataFor($diplome);

        foreach (config('diplomes.fields.' . $type, []) as $field) {
            $value = $data[$field['key']] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $this->addValueBox(
                $section,
                (string) $value,
                (float) $field['x'],
                (float) $field['y'],
                (int) ($field['size'] ?? 11),
                ($field['group'] ?? 'fr') === 'ar',
                (bool) ($field['bold'] ?? true),
                $field['key'],
                $pageW,
                $pageH,
                (float) ($field['width'] ?? $boxW),
                $colors
            );
        }

        // Establishment-level values, identical for every diploma: they fill the
        // blanks of the pre-typed certification text, each on the halves it
        // declares, at their calibrated positions.
        $informations = $this->informations();
        $infoPositions = $this->infoPositions($type);
        foreach (config('diplomes.infos', []) as $info) {
            $value = $informations[$info['key']] ?? '';
            if ($value === '') {
                continue;
            }
            $value = $this->formatInfoValue($info, $value);

            foreach (['fr', 'ar'] as $group) {
                $position = $infoPositions[$info['key']][$group] ?? null;
                if ($position === null) {
                    continue;
                }

                $this->addValueBox(
                    $section,
                    $value,
                    (float) $position['x'],
                    (float) $position['y'],
                    (int) ($position['size'] ?? 10),
                    $group === 'ar',
                    true, // every printed value stands out in bold
                    $info['key'],
                    $pageW,
                    $pageH,
                    $boxW,
                    $colors,
                    // Some facts declare their own alignment (the institute name
                    // is centered in both languages); the rest keep the anchor's
                    // default (left for 'fr', right for 'ar').
                    $info['align'] ?? null
                );
            }
        }

        // Trailing paragraph so Word keeps the absolutely-positioned shapes.
        $section->addText('');

        return $phpWord;
    }

    /**
     * Draw one field as an absolutely-positioned, transparent text box. 'fr'
     * values are anchored at their left edge, 'ar' at their right edge (Arabic
     * writing expands toward the left), matching the old renderer. A caller may
     * also pass an explicit alignment ('center' for the institute name): the
     * anchor is unchanged, only the text's alignment inside the box.
     */
    private function addValueBox(
        $section,
        string $value,
        float $x,
        float $y,
        int $size,
        bool $isAr,
        bool $bold,
        string $key,
        float $pageW,
        float $pageH,
        float $boxW,
        array $colors,
        ?string $align = null
    ): void {
        // A value on the Arabic half is only rendered with the Arabic face when
        // it actually contains Arabic letters. Identifiers and numbers (CIN,
        // inscription number, dates, the middle-text figures) carry no Arabic
        // glyphs, so they keep the French face and size and look exactly like
        // the French half instead of being enlarged and re-glyphed.
        $useArabicFace = $isAr && $this->hasArabicLetters($value);

        if ($useArabicFace) {
            $size += (int) config('diplomes.ar_size_adjust', 0);
            $size = max(6, $size);
        }

        $font = [
            'name' => (string) config('diplomes.font_' . ($useArabicFace ? 'ar' : 'fr'), 'Arial'),
            'size' => $size,
            'bold' => $bold,
        ];
        // The Arabic filière name alone wears a different face (diploma only,
        // IP only). Colours, bold and kashida are untouched; the French filière
        // and every other Arabic field keep the shared fonts.
        if ($useArabicFace && $key === 'filiere_ar' && $this->dedicatedFiliereFont) {
            $font['name'] = (string) config('diplomes.font_ar_filiere', $font['name']);
            // The dedicated face reads smaller than the shared Arabic one, so it
            // is nudged up a little — only that value (user request).
            $font['size'] = max(6, $size + (int) config('diplomes.font_ar_filiere_size_adjust', 0));
        }
        if ($useArabicFace) {
            $font['rtl'] = true;
        }

        $left = $x * $pageW;
        if ($isAr) {
            $left -= $boxW;
        }
        $top = $y * $pageH;

        $textbox = $section->addTextBox([
            'width' => $boxW,
            'positioning' => 'absolute',
            'posHorizontal' => 'absolute',
            'posVertical' => 'absolute',
            'hPos' => 'absolute',
            'vPos' => 'absolute',
            'posHorizontalRel' => 'page',
            'posVerticalRel' => 'page',
            'marginLeft' => $left,
            'marginTop' => $top,
            'wrap' => 'infront',
            // PhpWord emits no fill/stroke declaration for these values;
            // the post-processing step forces filled="f" stroked="f" so the
            // box is transparent (no background, no border) over the
            // template raster.
            'borderSize' => 0,
            'borderColor' => 'FFFFFF',
            'bgColor' => null,
            'fillColor' => null,
        ]);

        $run = $textbox->addTextRun([
            // A value declares its own alignment when it must sit centered in
            // its box (the institute name, in both languages); otherwise 'fr'
            // runs are left-aligned and 'ar' runs right-aligned, anchored at
            // their calibrated marker.
            'alignment' => match ($align) {
                'center' => Jc::CENTER,
                'right'  => Jc::RIGHT,
                'left'   => Jc::LEFT,
                default  => $isAr ? Jc::RIGHT : Jc::LEFT,
            },
        ]);

        $this->writeValue($run, $value, $font, $key, $useArabicFace, $colors);
    }

    /**
     * Date-typed information values are stored as ISO dates (the form uses a
     * date picker) but printed the French way (jj/mm/aaaa). Plain text and
     * numbers are returned untouched; an unparsable value is left as typed
     * rather than dropped.
     */
    private function formatInfoValue(array $info, string $value): string
    {
        if (($info['type'] ?? 'text') !== 'date') {
            return $value;
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable $e) {
            return $value;
        }
    }

    private function writeValue($run, string $value, array $font, string $key, bool $isAr, array $colors): void
    {
        $accented = in_array($key, ['filiere', 'type_formation', 'filiere_ar', 'type_formation_ar'], true);

        // Letters set closer (user request): the IP Arabic filière is printed
        // without the template's kashida stretching, so its letters sit next to
        // each other instead of being pulled apart by tatweels. Only that value:
        // every other Arabic field keeps the stretched template look.
        $stretch = !($this->dedicatedFiliereFont && $key === 'filiere_ar');

        if (!$accented) {
            $run->addText($this->arText($value, $isAr, $stretch), $font);

            return;
        }

        // The filière name keeps a lone French/Arabic connector word in black
        // ("En" / "في"), every other word accented; the formation type is one
        // uniform blue value, as in the previous renderer.
        if ($key === 'type_formation' || $key === 'type_formation_ar') {
            $run->addText($this->arText($value, $isAr, $stretch), array_merge($font, ['color' => $colors['accent']]));

            return;
        }

        $blackWord = $isAr ? 'في' : 'En';
        foreach (preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $wordFont = strcmp($word, $blackWord) === 0
                ? $font
                : array_merge($font, ['color' => $colors['accent']]);
            $run->addText($this->arText($word, $isAr, $stretch) . ' ', $wordFont);
        }
    }

    /**
     * Wrap an Arabic value so it takes the template's stretched kashida. The
     * IP Arabic filière passes $stretch = false: its letters stay close
     * together instead of being pulled apart by tatweels.
     */
    private function arText(string $value, bool $isAr, bool $stretch = true): string
    {
        return $isAr && $stretch ? $this->stretchArabic($value) : $value;
    }

    /**
     * True when the value contains at least one Arabic letter. Arabic-Indic
     * digits (٠-٩), Arabic punctuation and the tatweel are deliberately not
     * counted: an identifier, a number or a date must keep the French face and
     * size on the Arabic half, exactly like the French half.
     */
    private function hasArabicLetters(string $value): bool
    {
        return (bool) preg_match('/[\x{0621}-\x{063F}\x{0641}-\x{064A}]/u', $value);
    }

    /**
     * Repeat tatweel (kashida, U+0640) in each joinable gap so an Arabic value
     * takes the stretched calligraphic look of the template. Tatweel may only
     * follow a letter that connects forward, so alef/dal/reh/... are left as
     * they are, and digits, spaces and Latin never change.
     * `diplomes.ar_kashida` = how many tatweels per gap (0 disables it).
     */
    private function stretchArabic(string $text): string
    {
        $count = (int) config('diplomes.ar_kashida', 0);
        if ($count <= 0 || $text === '') {
            return $text;
        }

        $kashida = str_repeat("\u{0640}", $count);
        // Letters that do not connect to the following letter (right-joining
        // only), plus the non-joining hamza.
        $stop = ['ا', 'أ', 'إ', 'آ', 'ٱ', 'د', 'ذ', 'ر', 'ز', 'و', 'ؤ', 'ة', 'ء'];
        // A joinable Arabic letter; tatweel (U+0640) is excluded from the span.
        $isLetter = fn (string $ch): bool => (bool) preg_match('/[\x{0621}-\x{063F}\x{0641}-\x{064A}]/u', $ch);

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = count($chars) - 1;
        $out = '';
        foreach ($chars as $i => $ch) {
            $out .= $ch;
            if ($i >= $last) {
                continue;
            }
            $next = $chars[$i + 1];
            if (!$isLetter($ch) || !$isLetter($next) || $next === 'ء' || in_array($ch, $stop, true)) {
                continue;
            }
            $out .= $kashida;
        }

        return $out;
    }

    private function frDate($date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }
}