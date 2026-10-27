<?php

/*
 | Diploma document configuration.
 |
 | The editable .docx diplomas are built by DiplomaDocxService: one of three
 | template raster images (one per Filiere.type_formation) is laid under an
 | A4-landscape page, and each field is drawn as an absolutely-positioned,
 | editable Word text box.
 |
 | Field coordinates are normalized fractions of the page (x, y in 0..1). For a
 | 'fr' field the text box is anchored at its left edge; for an 'ar' field it is
 | anchored at its right edge (the writing starts from x toward the left, RTL).
 */

// A4 landscape in Word points (1 pt = 1/72 inch).
$widthPt = 842;
$heightPt = 595;

// Qualification's artwork sits slightly differently from Technicien and
// Specialisation, so each type carries its own coordinates while covering the
// same field set. The three arrays below are independent copies, so a type can
// be recalibrated alone without moving the others.
$fieldsTechnicien = [
    ['key' => 'nom_prenom', 'x' => 0.164, 'y' => 0.483, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_naissance', 'x' => 0.109, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'lieu_naissance', 'x' => 0.222, 'y' => 0.513, 'size' => 12, 'bold' => true, 'group' => 'fr'],
    ['key' => 'cin', 'x' => 0.189, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nationalite', 'x' => 0.362, 'y' => 0.541, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'numero_inscription', 'x' => 0.268, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_inscription', 'x' => 0.487, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'filiere', 'x' => 0.223, 'y' => 0.621, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.488, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.842, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'filiere_ar', 'x' => 0.821, 'y' => 0.625, 'size' => 16, 'bold' => true, 'group' => 'ar'],
];

$fieldsQualification = [
    ['key' => 'nom_prenom', 'x' => 0.164, 'y' => 0.483, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_naissance', 'x' => 0.109, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'lieu_naissance', 'x' => 0.222, 'y' => 0.513, 'size' => 12, 'bold' => true, 'group' => 'fr'],
    ['key' => 'cin', 'x' => 0.189, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nationalite', 'x' => 0.362, 'y' => 0.541, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'numero_inscription', 'x' => 0.268, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_inscription', 'x' => 0.487, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'filiere', 'x' => 0.331, 'y' => 0.622, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.488, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.842, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'filiere_ar', 'x' => 0.799, 'y' => 0.627, 'size' => 16, 'bold' => true, 'group' => 'ar'],
];

// Specialisation starts from the same values as Technicien but is its own list:
// recalibrating it never touches Technicien or Qualification.
$fieldsSpecialisation = [
    ['key' => 'nom_prenom', 'x' => 0.164, 'y' => 0.483, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_naissance', 'x' => 0.109, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'lieu_naissance', 'x' => 0.222, 'y' => 0.513, 'size' => 12, 'bold' => true, 'group' => 'fr'],
    ['key' => 'cin', 'x' => 0.189, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nationalite', 'x' => 0.362, 'y' => 0.541, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'numero_inscription', 'x' => 0.268, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_inscription', 'x' => 0.485, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'filiere', 'x' => 0.282, 'y' => 0.621, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.486, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.842, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'filiere_ar', 'x' => 0.786, 'y' => 0.613, 'size' => 15, 'bold' => true, 'group' => 'ar', 'width' => 300],
];

return [
    'page_width_pt' => $widthPt,
    'page_height_pt' => $heightPt,

    // Template raster per Filiere.type_formation (web path under backend/public).
    'templates' => [
        'Qualification' => '/templates/diplomes/Q.png',
        'Technicien' => '/templates/diplomes/T.png',
        'Specialisation' => '/templates/diplomes/TS.png',
    ],

    // Fallback used when a student has no usable filière.
    'default_type' => 'Technicien',

    // WinRAR CLI used to pack a generated batch into one .rar download
    // (one folder per filière). Override via WINRAR_PATH if installed elsewhere.
    'rar_path' => env('WINRAR_PATH', 'C:\\Program Files\\WinRAR\\Rar.exe'),

    // Fonts per language group. Arial on the French side (the traditional
    // Times New Roman remains a one-line revert), Traditional Arabic text on
    // the Arabic side is rendered at (field size + this adjust). GE Elegant
    // runs optically larger than the previous Naskh face, so it is only nudged
    // up by 1 pt; set 0 for identical sizes or negative for smaller-than-French
    // Arabic.
    'font_fr' => 'Arial',
    'font_ar' => 'GE Elegant',
    // The Arabic filière name alone is set in another face — and only for the
    // filière codes listed here (today IP, the Infirmier Polyvalent), only in
    // the diploma: the French filière and every other Arabic field keep the
    // fonts above, and the size is untouched (same field size +
    // ar_size_adjust).
    'font_ar_filiere' => 'Simplified Arabic',
    'font_ar_filiere_codes' => ['IP'],
    // The dedicated face reads smaller than the shared Arabic one at the same
    // point size, so the IP Arabic filière is nudged up by this many points —
    // added on top of ar_size_adjust, only for that value, only in the diploma.
    // (User request, 2026-10-19: « increase size for a bit ».)
    'font_ar_filiere_size_adjust' => 2,
    'ar_size_adjust' => (int) env('ARABIC_FONT_SIZE_ADJUST', 1),

    // How many tatweels (kashida, ـ U+0640) to repeat in each joinable gap of
    // an Arabic value, to reproduce the stretched look of the template. Only
    // letters that connect forward receive one; digits and Latin are untouched.
    // 0 disables it.
    'ar_kashida' => (int) env('DIPLOMA_AR_KASHIDA', 4),

    // Width in points of every editable text box. Height is left to Word
    // (auto-sizes so a long value wraps without overflowing the box). A field
    // may override it with its own 'width' key when its value is longer than
    // the others (e.g. Specialisation's Arabic filière).
    'box_width_pt' => 220,

    // Colors kept from the previous PDF renderer.
    'colors' => [
        'text' => '000000',
        'accent' => '0096FF',
    ],

    // Calibration per template type, each fully independent: recalibrating one
    // type never moves the other two.
    'fields' => [
        'Qualification' => $fieldsQualification,
        'Technicien' => $fieldsTechnicien,
        'Specialisation' => $fieldsSpecialisation,
    ],

    /*
     | The establishment-level facts that are the SAME for every diploma: the
     | middle certification text is pre-typed on the template in French and
     | Arabic, and these are the values that fill its blanks -- the opening
     | authorisation, the accreditation decision (its decision number, the
     | number it was registered under, and its date), the date of the jury's
     | examination minutes, and the diploma number. The establishment's own
     | identity -- the institute's name and the city, each language typed as
     | its own value -- is collected here as well and printed on its own
     | calibrated line. Each fact is drawn only on
     | the halves it declares, so a fact with no 'ar' marker prints on the
     | French half only (the Arabic half of the certification text then keeps
     | that blank empty). They are edited from the
     | "Informations du diplôme" form, and the admin overrides their marker
     | coordinates from "Calibrer les templates"; the chosen positions are
     | persisted in the diplome_parametres table. A fact may also declare an
     | 'align' (today only 'center', e.g. the institute name) to center its
     | value in its box instead of anchoring it to a side. The coordinates
     | below are the defaults for the French and Arabic halves; each template
     | type keeps its own calibrated set in the DB, so a type whose artwork
     | places a blank elsewhere (Specialisation, since 2026-10-21) can hold its
     | own values without moving the other two.
     */
    'infos' => [
        [
            'key'   => 'info_ouverture_date',
            'label' => "Date d'autorisation d'ouverture",
            'type'  => 'date',
            'fr'    => ['x' => 0.3532, 'y' => 0.4113, 'size' => 10],
            'ar'    => ['x' => 0.6503, 'y' => 0.4133, 'size' => 10],
        ],
        [
            'key'   => 'info_ouverture_no',
            'label' => "N° d'autorisation d'ouverture",
            'type'  => 'text',
            'fr'    => ['x' => 0.4779, 'y' => 0.4133, 'size' => 10],
        ],
        [
            'key'   => 'info_accreditation_no',
            'label' => "N° de décision d'accréditation",
            'type'  => 'text',
            'fr'    => ['x' => 0.3063, 'y' => 0.429, 'size' => 10],
            'ar'    => ['x' => 0.6349, 'y' => 0.4291, 'size' => 10],
        ],
        [
            'key'   => 'info_sous_no',
            'label' => 'N',
            'type'  => 'text',
            'fr'    => ['x' => 0.4849, 'y' => 0.2392, 'size' => 10],
        ],
        [
            'key'   => 'info_accreditation_date',
            'label' => "Date de la décision d'accréditation",
            'type'  => 'date',
            'fr'    => ['x' => 0.4667, 'y' => 0.4311, 'size' => 10],
        ],
        [
            'key'   => 'info_pv_date',
            'label' => 'Date du procès-verbal du jury',
            'type'  => 'date',
            'fr'    => ['x' => 0.4653, 'y' => 0.4489, 'size' => 10],
        ],
        [
            'key'   => 'info_serie_no',
            'label' => 'N° du diplôme (série)',
            'type'  => 'text',
            'fr'    => ['x' => 0.5186, 'y' => 0.9187, 'size' => 10],
        ],

        // The establishment's own identity, also the same on every diploma:
        // the institute's name and the city, typed once per language (the
        // French and Arabic texts are different values), each printed only on
        // the half its marker declares.
        [
            'key'   => 'institut_fr',
            'label' => "Nom de l'institut (français)",
            'type'  => 'text',
            // Centered in its box instead of aligned to the left (user request,
            // 2026-10-19); the box anchor is unchanged.
            'align' => 'center',
            'fr'    => ['x' => 0.2397, 'y' => 0.16, 'size' => 10],
        ],
        [
            'key'   => 'institut_ar',
            'label' => "Nom de l'institut (arabe)",
            'type'  => 'text',
            // Centered in its box instead of aligned to the right (user request,
            // 2026-10-19); the box anchor is unchanged.
            'align' => 'center',
            'ar'    => ['x' => 0.7568, 'y' => 0.16, 'size' => 10],
        ],
        [
            'key'   => 'ville_fr',
            'label' => 'Ville (français)',
            'type'  => 'text',
            'fr'    => ['x' => 0.3406, 'y' => 0.7042, 'size' => 10],
        ],
        [
            'key'   => 'ville_ar',
            'label' => 'Ville (arabe)',
            'type'  => 'text',
            // Qualified/Technician move this one together (their own calibration,
            // 2026-10-21); Specialisation keeps the 20/10 value for its artwork.
            'ar'    => ['x' => 0.6825, 'y' => 0.7026, 'size' => 10],
        ],
    ],
];
