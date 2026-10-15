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
    // Filière stays calibrated per template (the printed label sits differently
    // on the Technicien artwork).
    ['key' => 'filiere', 'x' => 0.238, 'y' => 0.625, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.488, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.848, 'y' => 0.519, 'size' => 9, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    // Filière (arabe) stays calibrated per template.
    ['key' => 'filiere_ar', 'x' => 0.817, 'y' => 0.629, 'size' => 13, 'bold' => true, 'group' => 'ar'],
];

$fieldsQualification = [
    ['key' => 'nom_prenom', 'x' => 0.164, 'y' => 0.483, 'size' => 16, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_naissance', 'x' => 0.109, 'y' => 0.512, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'lieu_naissance', 'x' => 0.222, 'y' => 0.513, 'size' => 12, 'bold' => true, 'group' => 'fr'],
    ['key' => 'cin', 'x' => 0.189, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nationalite', 'x' => 0.362, 'y' => 0.541, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'numero_inscription', 'x' => 0.268, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_inscription', 'x' => 0.487, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'filiere', 'x' => 0.335, 'y' => 0.625, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.488, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.848, 'y' => 0.519, 'size' => 9, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'filiere_ar', 'x' => 0.799, 'y' => 0.627, 'size' => 13, 'bold' => true, 'group' => 'ar'],
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
    ['key' => 'date_inscription', 'x' => 0.487, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    // Filière stays calibrated per template (the printed label sits differently
    // on the Specialisation artwork).
    ['key' => 'filiere', 'x' => 0.292, 'y' => 0.624, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'promotion', 'x' => 0.488, 'y' => 0.647, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'date_delivrance', 'x' => 0.465, 'y' => 0.698, 'size' => 13, 'bold' => true, 'group' => 'fr'],
    ['key' => 'nom_ar', 'x' => 0.875, 'y' => 0.487, 'size' => 16, 'bold' => true, 'group' => 'ar'],
    ['key' => 'date_naissance_ar', 'x' => 0.848, 'y' => 0.519, 'size' => 9, 'bold' => true, 'group' => 'ar'],
    ['key' => 'lieu_naissance_ar', 'x' => 0.716, 'y' => 0.516, 'size' => 12, 'bold' => true, 'group' => 'ar'],
    ['key' => 'cin_ar', 'x' => 0.802, 'y' => 0.540, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'nationalite_ar', 'x' => 0.656, 'y' => 0.543, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    ['key' => 'numero_inscription_ar', 'x' => 0.774, 'y' => 0.568, 'size' => 13, 'bold' => true, 'group' => 'ar'],
    // "ممرض متعدد الاختصاصات" is much longer than the other two filières; it
    // needs a wider box than the default so it stays on ONE line; its size
    // now matches the other filières, and the wider box does the fitting.
    // Its position stays calibrated per template.
    ['key' => 'filiere_ar', 'x' => 0.790, 'y' => 0.626, 'size' => 13, 'bold' => true, 'group' => 'ar', 'width' => 300],
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
     | persisted in the diplome_parametres table.
     */
    'infos' => [
        [
            'key'   => 'info_ouverture_date',
            'label' => "Date d'autorisation d'ouverture",
            'type'  => 'date',
            'fr'    => ['x' => 0.440, 'y' => 0.418, 'size' => 10],
            'ar'    => ['x' => 0.650, 'y' => 0.424, 'size' => 10],
        ],
        [
            'key'   => 'info_ouverture_no',
            'label' => "N° d'autorisation d'ouverture",
            'type'  => 'text',
            'fr'    => ['x' => 0.352, 'y' => 0.414, 'size' => 10],
        ],
        [
            'key'   => 'info_accreditation_no',
            'label' => "N° de décision d'accréditation",
            'type'  => 'text',
            'fr'    => ['x' => 0.360, 'y' => 0.436, 'size' => 10],
            'ar'    => ['x' => 0.640, 'y' => 0.442, 'size' => 10],
        ],
        [
            'key'   => 'info_sous_no',
            'label' => 'N',
            'type'  => 'text',
            'fr'    => ['x' => 0.500, 'y' => 0.426, 'size' => 10],
        ],
        [
            'key'   => 'info_accreditation_date',
            'label' => "Date de la décision d'accréditation",
            'type'  => 'date',
            'fr'    => ['x' => 0.478, 'y' => 0.441, 'size' => 10],
        ],
        [
            'key'   => 'info_pv_date',
            'label' => 'Date du procès-verbal du jury',
            'type'  => 'date',
            'fr'    => ['x' => 0.478, 'y' => 0.459, 'size' => 10],
        ],
        [
            'key'   => 'info_serie_no',
            'label' => 'N° du diplôme (série)',
            'type'  => 'text',
            'fr'    => ['x' => 0.503, 'y' => 0.927, 'size' => 10],
        ],

        // The establishment's own identity, also the same on every diploma:
        // the institute's name and the city, typed once per language (the
        // French and Arabic texts are different values), each printed only on
        // the half its marker declares.
        [
            'key'   => 'institut_fr',
            'label' => "Nom de l'institut (français)",
            'type'  => 'text',
            'fr'    => ['x' => 0.250, 'y' => 0.175, 'size' => 10],
        ],
        [
            'key'   => 'institut_ar',
            'label' => "Nom de l'institut (arabe)",
            'type'  => 'text',
            'ar'    => ['x' => 0.750, 'y' => 0.175, 'size' => 10],
        ],
        [
            'key'   => 'ville_fr',
            'label' => 'Ville (français)',
            'type'  => 'text',
            'fr'    => ['x' => 0.330, 'y' => 0.710, 'size' => 10],
        ],
        [
            'key'   => 'ville_ar',
            'label' => 'Ville (arabe)',
            'type'  => 'text',
            'ar'    => ['x' => 0.676, 'y' => 0.700, 'size' => 10],
        ],
    ],
];
