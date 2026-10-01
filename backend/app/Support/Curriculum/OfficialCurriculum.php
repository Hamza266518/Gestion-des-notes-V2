<?php

namespace App\Support\Curriculum;

/**
 * Authoritative curriculum coefficients.
 *
 * SOURCE OF TRUTH: "word/Tableau des Coefficient des unités.docx"
 *   Table 1 -> AS   (Aide-Soignant, annee 1)
 *   Table 2 -> IA1  (Infirmier Auxiliaire, annee 1)  -- UNRELIABLE, see self::UNRESOLVED
 *   Table 3 -> IA2  (Infirmier Auxiliaire, annee 2)
 *   Table 4 -> IP1  (Infirmier Polyvalent, annee 1)
 *   Table 5 -> IP2  (Infirmier Polyvalent, annee 2)
 *   Table 6 -> IP3  (Infirmier Polyvalent, annee 3)
 *
 * Every entry is transcribed verbatim from the document. Unit names are stored
 * exactly as written in the official table so that the audit can report an
 * explicit, reviewable diff against the database instead of silently guessing.
 *
 * The official table for IA1 is a byte-for-byte copy of the AS table, which is
 * contradicted by "Suivi Controle.docx" and "Les Cahiers De Suivi.docx". It is
 * therefore deliberately NOT treated as authoritative for IA1.
 */
final class OfficialCurriculum
{
    /**
     * Official unit coefficients, keyed by filiere code, then annee.
     *
     * @return array<string, array<int, array{code:string, nom:string, coefficient:int}>>|null
     *   null means "no authoritative source available" for that programme/year.
     */
    public static function coefficients(): array
    {
        return [
            'AS' => [
                1 => [
                    ['code' => 'AS', 'nom' => "Eléments de sciences biologiques", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Hygiène et confort du malade", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et du confort du malade en médecine et aux urgences", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et du confort du malade en chirurgie et en réanimation", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Introduction aux soins d'hygiène et du confort du malade", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => 'Hygiène', 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et confort en Pédiatrie", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et confort en Psychiatrie", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et du confort en Gynéco-obstétrique", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "Soins d'hygiène et du confort en Gériatrie et aux soins palliatifs", 'coefficient' => 4],
                    ['code' => 'AS', 'nom' => "santé publique", 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => 'Relations et Communication', 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => 'Eléments de Sociologie-psychologie', 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => 'Eléments de Pharmacologie', 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => 'Droit-Législation', 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => 'Langue', 'coefficient' => 2],
                    ['code' => 'AS', 'nom' => "Notion d'informatique", 'coefficient' => 2],
                ],
            ],
            'IA' => [
                1 => null,
                2 => [
                    ['code' => 'IA', 'nom' => 'Symptomatologie et notion de pathologie', 'coefficient' => 4],
                    ['code' => 'IA', 'nom' => 'Maladies transmissibles et prophylaxie', 'coefficient' => 4],
                    ['code' => 'IA', 'nom' => 'Hygiène', 'coefficient' => 4],
                    ['code' => 'IA', 'nom' => 'Urgences et secours', 'coefficient' => 4],
                    ['code' => 'IA', 'nom' => 'Démographie et espacement des naissances et système d\'information', 'coefficient' => 3],
                    ['code' => 'IA', 'nom' => 'Programme de lutte contre les maladies', 'coefficient' => 3],
                    ['code' => 'IA', 'nom' => 'Notion de droit et de gestion', 'coefficient' => 2],
                    ['code' => 'IA', 'nom' => 'Notion en méthodologie de recherche', 'coefficient' => 2],
                ],
            ],
            'IP' => [
                1 => [
                    ['code' => 'IP', 'nom' => 'Anatomie physiologie', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Sciences biologiques', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Soins infirmiers de base', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Pathologie pédiatrique et soins infirmiers', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Nutrition et puériculture', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Sémiologie et terminologie', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Introduction aux sciences infirmières', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Relations et communications', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => "Programmes d'activités sanitaires", 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Sciences sociales et humaines', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Elément de droit', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Statistique sanitaire et démographie', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Système national de santé', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Notions d’informatiques', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Langue', 'coefficient' => 2],
                ],
                2 => [
                    ['code' => 'IP', 'nom' => 'Pathologie médicaleet soins infirmiers', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Pathologie chirurgicale et soins infirmiers', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Epidémiologie et maladies transmissibles', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Pathologie en réanimation médico-chirurgicale et soins infirmiers', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Economie de santé', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Soins infirmiers en bloc opératoire', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Obstétrique et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Régime alimentaire', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Santé bucco-dentaire', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Gériatrie et soins infirmiers aux personnes âgées', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Psychiatrie et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => "Programmes d'activités sanitaires", 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Methodologie de recherche', 'coefficient' => 2],
                ],
                3 => [
                    ['code' => 'IP', 'nom' => 'Dermatologie et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Urgences et secours', 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Oto-rhino-laryngologie et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Ophtalmologie et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => 'Qualité et genre de soins', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Gynécologie et soins infirmiers', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => "Programmes d'activités sanitaires", 'coefficient' => 4],
                    ['code' => 'IP', 'nom' => 'Principe de gestion', 'coefficient' => 2],
                    ['code' => 'IP', 'nom' => 'Santé au travail', 'coefficient' => 3],
                    ['code' => 'IP', 'nom' => "Diagnostic de santé d'une population et planification des actions de santé", 'coefficient' => 3],
                ],
            ],
        ];
    }

    /**
     * Units that every programme/year must have as a real, counted unit.
     * Coefficient 4 in all six official tables.
     */
    public const STAGE_COEFFICIENT = 4;

    /**
     * Final mark formula published in the official recap sheets.
     * NF = 0.30*MPCC + 0.20*MPEFCFT + 0.30*MPEFCFP + 0.20*NSTI
     */
    public const FINAL_WEIGHTS = [
        'MPCC'    => 0.30,
        'MPEFCFT' => 0.20,
        'MPEFCFP' => 0.30,
        'NSTI'    => 0.20,
    ];

    /**
     * Programme/year pairs where the official coefficient table cannot be trusted.
     *
     * @return array<string, string>
     */
    public static function unresolved(): array
    {
        return [
            'IA:1' => 'La table IA1 du document est une copie exacte de la table AS et contredit Suivi Controle.docx et Les Cahiers De Suivi.docx. Coefficients IA1 non appliques.',
        ];
    }

    /**
     * Programme/year pairs where official documents disagree with each other.
     * These are never auto-applied; they are reported for a human decision.
     *
     * @return array<string, string>
     */
    public static function conflicts(): array
    {
        return [
            'IA:2|Démographie et espacement des naissances et système d\'information'
                => 'Le document IA2 fusionne Démographie (unite 454) et Système d\'information (unite 452) en une seule unité coef 3. La fusion est appliquee par migration dediee.',
            'IP:Qualité et genre de soins'
                => 'La table officielle IP3 definit cette unité coef 2, mais la base la range en IP1. Coefficient applique, annee non deplacee.',
            'IP:Statistique sanitaire et démographie'
                => 'La table officielle IP1 definit cette unité coef 2, mais la base la range en IP2. Coefficient applique, annee non deplacee.',
        ];
    }

    /**
     * Explicit database-name -> official-name bindings.
     *
     * The official table uses a slightly different label than the database for a
     * handful of units that are unambiguously the same unit. Only entries that
     * were verified by hand against the documents belong here; nothing is
     * inferred at runtime.
     *
     * @return array<string, array<string, array{0:string, 1:int}>>  filiere => annee => [ dbName => [officialName, coefficient] ]
     */
    public static function aliases(): array
    {
        return [
            'AS' => [
                1 => [
                    "Hygiène : individuelle, envir, hospitalière" => ['Hygiène', 4],
                    "Surveillance de l'état d'hygiène et confort du malade" => ['Hygiène et confort du malade', 4],
                    'Élément de sciences biologique' => ['Eléments de sciences biologiques', 4],
                    "Soins d'hygiène et confort en médecine et urgences" => ["Soins d'hygiène et du confort du malade en médecine et aux urgences", 4],
                    "Soins d'hygiène et confort en chirurgie et réanimation" => ["Soins d'hygiène et du confort du malade en chirurgie et en réanimation", 4],
                    'Relation, communication' => ['Relations et Communication', 2],
                    "Notions d'informatique" => ["Notion d'informatique", 2],
                ],
            ],
            'IA' => [
                2 => [
                    'Symptomatologie et Notions de pathologie' => ['Symptomatologie et notion de pathologie', 4],
                    'Programmes de lutte' => ['Programme de lutte contre les maladies', 3],
                    'Démographie et espacement des naissances' => ['Démographie et espacement des naissances et système d\'information', 3],
                    'Éléments de droit et de gestion' => ['Notion de droit et de gestion', 2],
                ],
            ],
            'IP' => [
                1 => [
                    'Relation et communication' => ['Relations et communications', 3],
                    'Éléments de droit' => ['Elément de droit', 2],
                    "Notions d'informatique" => ['Notions d’informatiques', 2],
                ],
                2 => [
                    'Régimes alimentaires' => ['Régime alimentaire', 3],
                    'Médecine' => ['Pathologie médicaleet soins infirmiers', 4],
                    'Chirurgie' => ['Pathologie chirurgicale et soins infirmiers', 4],
                    'Réanimation' => ['Pathologie en réanimation médico-chirurgicale et soins infirmiers', 4],
                    'Gériatrie' => ['Gériatrie et soins infirmiers aux personnes âgées', 4],
                    'Obstétrique' => ['Obstétrique et soins infirmiers', 3],
                    'Psychiatrie' => ['Psychiatrie et soins infirmiers', 3],
                    'Méthodologie de recherche' => ['Methodologie de recherche', 2],
                ],
                3 => [
                    'Gynécologie' => ['Gynécologie et soins infirmiers', 3],
                    'Ophtalmologie' => ['Ophtalmologie et soins infirmiers', 3],
                    'Oto-rhino-laryngologie' => ['Oto-rhino-laryngologie et soins infirmiers', 3],
                    'Dermatologie' => ['Dermatologie et soins infirmiers', 3],
                    'Diagnostic de santé' => ["Diagnostic de santé d'une population et planification des actions de santé", 3],
                    'Principes de gestion' => ['Principe de gestion', 2],
                ],
            ],
        ];
    }

    /**
     * Units that an official table defines, but for a different year than the one
     * the database currently files them under.
     *
     * The coefficient is applied because the documents state it unambiguously;
     * moving the unit to the documented year is a structural change and is
     * therefore left to an explicit decision (see self::conflicts()).
     *
     * @return array<string, array{coefficient:int, annee:int, nom:string}>
     */
    public static function crossYearUnits(): array
    {
        return [
            'Qualité et genre de soins' => [
                'coefficient' => 2,
                'annee' => 3,
                'nom' => 'Qualité et genre de soins',
            ],
            'Statistique sanitaire et démographie' => [
                'coefficient' => 2,
                'annee' => 1,
                'nom' => 'Statistique sanitaire et démographie',
            ],
        ];
    }

    /**
     * Units the database holds that appear in no official table for their year.
     * They are reported so a human can decide; nothing is deleted automatically.
     *
     * @return array<string, string>
     */
    public static function notInOfficialTable(): array
    {
        return [
            'Médecine' => 'IP1 S2 : aucune table officielle ne liste cette unité pour l\'annee 1.',
            'Chirurgie' => 'IP1 S2 : aucune table officielle ne liste cette unité pour l\'annee 1.',
            'Pédiatrie' => 'Presente en IP1 S2 et IP2 S1 dans la base, absente des tables officielles.',
        ];
    }


    /**
     * Normalise a unit name for tolerant comparison (accents, case, punctuation,
     * leading articles, plural "s"). Used only for matching/reporting.
     */
    public static function normalise(string $name): string
    {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = strtr($s, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ù' => 'u',
            'ç' => 'c', '’' => ' ', '‘' => ' ', "'" => ' ',
        ]);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\b(le|la|les|l|de|du|des|d|et|et de|et des|en|au|aux|a|the)\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? $s;
        return $s;
    }
}
