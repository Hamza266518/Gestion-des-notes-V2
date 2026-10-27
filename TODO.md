# TODO

## À faire plus tard — décidé par l'utilisateur
- [ ] `Grille d'Examen du Stage` : a son **propre** traitement du `service` ; **ne pas y toucher**, l'utilisateur
  s'en occupe / le corrigera lui-même plus tard.
- [ ] **Bulletin étudiant (`MonBulletin`)** : ajouter un **3e bouton à côté de « S1 / S2 »** pour voir les notes
  du **Stage service par service** (le Stage n'appartient ni à S1 ni à S2). Décidé par l'utilisateur,
  à faire plus tard.
- [x] **Récapitulatif `.docx`** : ajouté les **deux pages manquantes** de la référence
  (`word/1 récap des notes IP 3 eme 2024 2025.docx`, 12 pages) au sélecteur, au `.docx` unitaire et à
  « Tous les tableaux » :
  - **Stage** — « Tableau récapitulatif des résultats de stage (durant tout le cursus de formation) » :
    bandeau de titre pleine largeur, colonnes `Note d'évaluation` / `Note d'examen pratique` (coef 4 / 4,
    moyennes par service de l'unité `Stage` en `stage_notes`), colonne *Proposition* ; **pas** de colonne
    ni de ligne de moyenne, comme la référence.
  - **Synthèse (NF)** — `MPCC*` (30 %) / `MPEFCFT**` (20 %) / `MPEFCFP***` (30 %) / `NSTI****` (20 %) /
    `NF*****` / *Proposition* : chaque colonne est la moyenne de l'élève sur la grille globale
    correspondante, NF = moyenne pondérée 30/20/30/20 ; **pas** de ligne de titre ni de moyenne de classe.
    Vérifié à l'identique sur la cohorte de la référence (HAJI Nabil : stage 14,38 / 14,46 ; synthèse
    16,12 / 14,36 / 13,65 / 14,00 / **NF 14,60**).
  - `RecapNoteDocxService` (géométrie par type via `gradeGeometry()` : largeurs, titre, colonne/ ligne de
    moyenne) ; `NoteAdminController::computeRecapData()` ; `RecapTable.jsx` (`TABLE_TYPES`, colonne
    moyenne masquée pour le Stage). Tests :
    `RecapNoteDocxTest::test_stage_sheet_lists_the_two_stage_marks_and_no_average_column` et
    `::test_synthesis_sheet_weights_the_four_global_marks_into_the_final_nf` ; le `.docx` combiné passe de
    10 à **12 pages**, une par tableau.
- [ ] **NSTI** : définir **comment les notes sont produites** (saisie par critère ? reprise du jury ?
  calcul ?). Le tableau est créé et volontairement laissé **vide** en attendant.

## Fait
- [x] **Calibrage des repères verts séparé par template (21/10)** — les 6 valeurs ajustées le 21/10 ne
  s'appliquent plus qu'à **Qualification et Technicien** ; **Specialisation garde ses valeurs du 20/10**
  (`info_ouverture_date` fr y 0.4074 / ar 0.4093, `info_ouverture_no` fr y 0.4113, `info_accreditation_date`
  fr y 0.4291, `info_pv_date` fr y 0.4469, `ville_ar` 0.6839/0.6953), car son fond n'est pas le même
  (décision utilisateur). Les 7 autres repères restent identiques aux trois types. La modale de calibrage
  n'enregistre plus qu'**un seul template à la fois** (celui affiché) et l'aperçu « Coordonnées actuelles »
  montre les trois jeux tels qu'ils sont stockés ; le backend était déjà par type. Vérifié par impression
  réelle : Q/T ouverture_date y 0.4179, ouverture_no 0.4200, accreditation_date 0.4379, pv_date 0.4557 ;
  TS 0.4141 / 0.4179 / 0.4357 / 0.4536. Test de régression ajouté
  (`test_saving_one_type_leaves_the_other_templates_positions_alone`), suite **37 passés / 390 assertions**.
- [x] **Repères verts recalés sur les nouveaux fonds (21/10)** — 6 valeurs ajustées, identiques pour les trois
  types (config + base) : `info_ouverture_date` fr y 0.4074 → **0.4113** et ar 0.4093 → **0.4133**,
  `info_ouverture_no` fr y 0.4113 → **0.4133**, `info_accreditation_date` fr y 0.4291 → **0.4311**,
  `info_pv_date` fr y 0.4469 → **0.4489**, `ville_ar` 0.6839/0.6953 → **0.6825/0.6994** ; les x et les autres
  repères (accreditation_no, sous_no, serie_no, institut, ville_fr) inchangés. Vérifié : config == base ==
  JSON collé (13 repères × 3 types) et impression sur de vrais diplômes Q/T/TS rendus sur les nouveaux fonds
  **couleur** (ouverture_date fr y 0.4179, ouverture_no y 0.4200, accreditation_date y 0.4379, pv_date
  y 0.4557, ouverture_date ar « 16/06/2023 » y 0.4200, ville commune « Berkane ... » finissant à x 0.6772).
- [x] **Fonds de diplômes mis à jour (21/10) : PDF sources → rasters recalés** — l'utilisateur a fourni
  `frontend/src/assets/{Q,T,TS}_Dip_consignes.pdf` (nouveaux fonds). Les PDF ont un format de page
  différent (1717x1179/1177 pt, dessin **décalé de ~31-37 px vers le bas** sur la page) ; un simple
  étirement page→page aurait déplacé tous les repères calibrés de ~8 pt. Les rasters
  `backend/public/templates/diplomes/{Q,T,TS}.png` (3508x2480) sont régénérés en **conservant la position
  du dessin** : rendu du PDF à la même échelle (largeur 3508) puis translation verticale (+35 / +37 / +33 px)
  déterminée par corrélation de profils de lignes. Vérification : **toutes les lignes de cadre (h et v)
  identiques au pixel** entre ancien et nouveau raster pour les trois types, bounding box du dessin
  identique (delta 0/0), encre à ±1 %, et chaque valeur imprimée sur un vrai diplôme Q/T/TS retombe
  exactement aux mêmes coordonnées qu'avant (sous_no y 0.2459, serie_no y 0.9254, filières aux ancres
  0.331/0.223/0.282). **Aucune coordonnée de calibrage modifiée.** Les PDF sources restent dans
  `frontend/src/assets` (choix utilisateur) ; le générateur lit toujours les PNG.
- [x] **Recalage du 20/10 : champs Specialisation + nouvel ensemble partagé d'informations** — l'utilisateur
  a re-calibré **Specialisation uniquement** (Qualification et Technicien inchangés, vérifié par diff) :
  `date_inscription` 0.487 → **0.485**, `filiere` 0.292/0.624 → **0.282/0.621**, `promotion` 0.488 → **0.486**,
  `filiere_ar` 0.797/0.616 → **0.786/0.613** (taille 15 et largeur 300 inchangées). Nouvel ensemble
  d'informations (vertes) **identique pour les trois types**, écrit en config et en base :
  ouverture_date 0.3532/0.4074 (+ ar 0.6503/0.4093), ouverture_no 0.4779/0.4113, accreditation_no ar
  0.6349/0.4291, sous_no 0.4849/0.2392, accreditation_date 0.4667/0.4291, pv_date 0.4653/0.4469, serie_no
  0.5186/0.9187, ville_fr 0.3406/0.7042, ville_ar 0.6839/0.6953 (institut inchangé). Vérifié sur un vrai
  diplôme TS rendu : filière fr **une ligne** commençant à x 0.2906 (attendu 0.2909), filière ar **une ligne**
  à 18 pt Simplified Arabic sans collision avec la promotion, et chaque valeur d'information bien à sa nouvelle
  coordonnée.
- [x] **Recalage du 20/10 (suites) : filières Q puis T** — après le recadrage TS, l'utilisateur a aussi
  déplacé la filière de Qualification (`filiere` 0.335/0.625 → **0.331/0.622**) puis celle de Technicien
  (`filiere` 0.238/0.625 → **0.223/0.621**, `filiere_ar` 0.817/0.629 → **0.821/0.625**) ; config + miroirs
  `DEFAULT_FIELDS_*` du formulaire mis à jour, tous les autres champs inchangés (diff du snippet).
- [x] **Repères verts : position unique pour les trois templates — décision confirmée (20/10)** — comparaison
  pixel des trois dessins : les trois fonds viennent de trois documents sources différents, donc les blancs ne
  sont pas au même endroit (ex. le blanc du « sous n° » : Q 395–507 pt vs TS 380–504 pt ; le bloc
  accréditation de TS est dessiné ~3 pt plus haut). L'utilisateur a confirmé : **les repères verts (informations)
  gardent une seule position partagée pour les trois types** — pas d'adaptation par template — et **les champs
  étudiant (rouge/bleu) ne sont pas touchés**. Vérifié sur diplômes réels Q #230 et TS #232 rendus depuis la
  base live : chaque valeur s'imprime aux **mêmes coordonnées** sur les deux, y compris le dernier déplacement
  (`info_sous_no` 0.4849/0.2392), et aucun cache n'existe dans la génération (single ni batch).
- [x] **Nom de la filière de Specialisation (TS) — 16 pt (fr) / 18 pt (ar imprimé)** (demandes utilisateur du
  20/10 : « make text for filiere name for TS 16 size and for arabic 18 », puis « i said 18 for ar not 21 ») :
  `filiere` 13 → **16** ; `filiere_ar` réglé à **15** pour que l'arabe s'imprime en exactement **18 pt**
  (15 + `ar_size_adjust` 1 + `font_ar_filiere_size_adjust` 2 — le filière de TS étant IP, il garde sa police
  dédiée Simplified Arabic et la **taille imprimée** n'est pas la taille du champ de l'autre côté).
  `config/diplomes.php` + miroir `DEFAULT_FIELDS_SPECIALISATION` ; largeur de boîte 300 conservée. Vérifié sur
  un vrai diplôme TS rendu puis ouvert dans Word/PDF : **une seule ligne** des deux côtés (fr 155,7 pt /
  cadre utile 205,6 pt, ar 165,4 pt / cadre utile 285,6 pt).
- [x] **Repères d'informations : comparaison des 3 types + purge (20/10)** — comparaison ligne à ligne :
  les 13 repères que l'utilisateur a collés sont **identiques** pour Qualification, Technicien et
  Specialisation, en config, en base **et** à l'impression (un vrai diplôme Q et un vrai diplôme TS rendus puis
  comparés dans le PDF : chaque valeur tombe aux **mêmes coordonnées**, ex. ville y 0.7082 / x 0.3521–0.6786
  sur les deux). Les lignes héritées de l'ancien calibrage par type restées en base (`info_visa_no`,
  `info_visa_date`, `info_delivrance_date` et les moitiés `ar` de `info_sous_no` / `info_ouverture_no` /
  `info_serie_no` / `info_accreditation_date`) ont été **supprimées** : la config reste la liste de référence et
  ces entrées ne s'imprimaient déjà pas (69 → 33 entrées, soit 13 repères × 3 types).
- [x] **Nom de la filière (fr + ar) en 16 pt — Technicien et Qualification** (demande utilisateur du 20/10 :
  « make name of filliere for T and Q ar and fr 16 in size ») : `filiere` et `filiere_ar` passent de 13 à
  **16** dans `config/diplomes.php` pour **Technicien et Qualification uniquement** ; le côté arabe s'imprime
  donc en 16 + `ar_size_adjust` (1) = **17 pt** (GE Elegant). **Specialisation (TS) reste à 13 pt** et le
  filière IP garde sa police dédiée (+2 pt). Vérifié en rendant les trois diplômes et en les ouvrant dans Word :
  les deux noms tiennent toujours sur **une seule ligne** (fr 115,7 / 147,8 pt, ar 155,4 / 150,9 pt pour un
  cadre utile de 205,6 pt) — donc aucune largeur de boîte à élargir. Défauts miroir
  `DEFAULT_FIELDS_TECHNICIEN` / `DEFAULT_FIELDS_QUALIFICATION` dans `Diplomes.jsx` (build + tests OK).
- [x] **Police de la « Filière » arabe — diplôme seulement, filière IP uniquement** : le nom arabe de la
  filière du **diplôme** s'imprime en **Simplified Arabic** (`diplomes.font_ar_filiere`), taille = taille du
  champ + `ar_size_adjust` + `font_ar_filiere_size_adjust` (**+2 pt**, demande utilisateur du 19/10 :
  « increase size for a bit »), **filière française inchangée** ; **les autres filières ne
  changent pas** (`diplomes.font_ar_filiere_codes = ['IP']`, liste extensible). Le texte arabe de la filière
  IP n'est **plus étiré** par le kashida (lettres resserrées, demande utilisateur : « les lettres semblent
  trop écartées ») ; **tous les autres champs arabes gardent l'étirement du modèle**. Relevé, bulletin et
  récapitulatif : **inchangés** (le réglage vit uniquement dans `DiplomaDocxService`). Tests :
  `DiplomeDocxTest::test_ip_diplomas_print_the_arabic_filiere_in_the_dedicated_font_and_size`,
  `::test_non_ip_diplomas_keep_the_shared_arabic_font_on_the_filiere`,
  `::test_ip_arabic_filiere_keeps_its_letters_close_together`.
- [x] **Nom de l'institut — centré (diplôme)** : les deux valeurs `institut_fr` et `institut_ar` s'impriment
  **centrées dans leur boîte** au lieu d'être collées à gauche (fr) / à droite (ar), comme le reste du texte
  du diplôme (demande utilisateur du 19/10 : « make them centered like the text »). Les autres informations
  (`ville_*`, dates, numéros) gardent leur alignement d'ancrage. Réglé par une clé `'align' => 'center'` sur
  la fiche `infos` (`config/diplomes.php`), lisible par `addValueBox()`. Test :
  `DiplomeDocxTest::test_institute_name_is_centered_on_both_halves`.
- [x] **Recalibrage remonté dans `config/diplomes.php` + mêmes repères d'informations pour les 3 templates**
  (demande utilisateur du 19/10 : « all have same infos places from formulaire ») ; **corrigé le 20/10**
  (« i was wrong in placing Q … the change was for the TS ») :
  - `filiere_ar` du **diplôme** : le décalage x 0.797 / y 0.616 est celui de **Specialisation (TS)** (où il
    avait été calibré, largeur `width` 300 conservée), **pas** de Qualification — Q est revenue à sa position
    d'origine x 0.799 / y 0.627. Technicien (x 0.817 / y 0.629) **inchangé**.
  - Défauts `infos` de `config/diplomes.php` toujours repris de Qualification — `institut_fr` x 0.2397,
    `institut_ar` x 0.7568, `ville_fr` y 0.7014, `ville_ar` x 0.6839 — et positions enregistrées en base
    (`diplome_parametres.positions`) **alignées sur ces mêmes valeurs pour les trois types** (Technicien et
    Specialisation portaient encore 0.2509/0.7484/0.7054/0.6853).
  - Le formulaire « Calibrer les templates » ne garde plus qu'**une seule liste de repères verts**, partagée
    par les trois onglets : glisser un repère sur un template le déplace sur tous, et « Enregistrer les
    positions » écrit la même liste pour les trois (`Diplomes.jsx`) — les repères des **champs** (rouge/bleu)
    restent, eux, propres à chaque type. Aucun changement côté `DiplomaDocxService` (la base reste prioritaire
    sur le défaut de config).
- [x] **Impression : suppression des boutons « Imprimer »** : les boutons d'impression maison et **tout** le code
  associé ont été supprimés (bulletin, récapitulatif, liste des étudiants, grilles de stage) — l'impression se fait
  désormais avec **Ctrl+P** du navigateur.
  - Fichiers supprimés : `components/common/PrintChoice.jsx`, `hooks/usePrintSheets.js`, `utils/printDocument.js`.
  - Boutons / feuilles masquées retirés de `BulletinSheet.jsx`, `Notes.jsx` (`RECAP_PRINT_CSS` inclus),
    `Etudiants.jsx` (ancien panneau « Liste des emails des étudiants » : bouton, filtres, overlay et états
    `print*`) et des trois grilles de stage (`GardeDeNuit.jsx`, `GrilleEvaluationStage.jsx`,
    `GrilleExamenStage.jsx`, qui écrivaient leur propre fenêtre HTML).
  - `Bulletins.jsx` : `loadGroupBulletins` / `groupSize` (ajoutés pour le tirage de groupe) supprimés ; les deux
    boutons **.docx** (un bulletin / tous les bulletins) restent.
  - CSS : le panneau `print-overlay` et ses styles sont supprimés ; il reste un bloc `@media print` minimal
    (barre latérale, menu, filtres et boutons masqués ; plus de `page-main` masqué ni de colonnes supprimées) pour
    que **Ctrl+P** imprime la page affichée. Le bloc `@media print` de `pages.css` (couleurs des tableaux du relevé
    et du bulletin) est conservé.
- [x] **Nouveau tableau `NSTI` — « Critères arrêtés par le jury d'examens »** : ajouté au récapitulatif
  (sélecteur + `.docx`), reproduit la grille de la référence IP3 (`word/1 récap des notes IP 3 eme 2024 2025.docx`) :
  10 critères avec leurs coefficients (3/2/2/2/2/1/3/2/2/1), colonne **NSTI\*** et colonne
  « Proposition/ commentaire de jury d'examen ». Le tableau est **vide** (lignes élèves sans note, aucune
  moyenne) tant que la règle de calcul des notes n'est pas définie. Colonnes définies dans
  `NoteAdminController::NSTI_CRITERIA` ; libellés dans `RecapNoteDocxService` (`tableTitleFor`,
  `avgLabelFor`, `obsLabelFor`, `tableLabelFor`). Test : `RecapNoteDocxTest::test_nsti_sheet_lists_the_jury_criteria_and_stays_empty`.
- [x] **Le Stage (unité annuelle) ne figure que dans les tableaux globaux** : l'unité `Stage` est exclue de
  **tous** les tableaux par semestre — `mpcc1` / `mpcc2`, `mpefcf1` / `mpefcf2`, `mpefcfp1` / `mpefcfp2` — ainsi
  que du tableau global **théorique** (`mpefcf_global`). Elle ne reste que dans le **MPCC global** (fin de
  cursus) et le **global des examens pratiques** (`mpefcfp_global`), comme la référence. Test :
  `RecapNoteDocxTest::test_stage_unit_is_a_year_unit_kept_out_of_the_per_semestre_tables`.
- [x] **IA an 1** : l'unité `Stage` manquait (seule l'unité 502 « Notion de méthodologie de recherche (Stage) »
  existait) → le select des services était vide. Création de l'unité **507 `Stage`** (IA, 1ère année, coef 4,
  semestre 2, sans séquence) avec ses **6 services** issus de `Programme Des Stages.docx` (colonne 1ère Année) :
  Médecine, Chirurgie, Pédiatrie, Maternité + maison d'accouchement, Dispensaire, Centre de santé.
  - `database/data/stage_services.json` : ajout de la clé **`IA|1`**.
  - Migration `2026_10_13_000001_create_missing_stage_units` : crée l'unité + ses contrôles pour toute paire
    filière/année qui a des services dans le JSON mais aucune unité `Stage` active (idempotent).
  - Migration `2026_10_13_000002_remove_duplicate_stage_unit` : supprime l'ancienne unité **502**
    « Notion de méthodologie de recherche (Stage) » (coquille vide : 1 séquence `Stage`, 2 contrôles, 0 note)
    là où une unité `Stage` canonique existe déjà. Ne supprime jamais une unité portant une note.
- [x] **Le Stage n'est compté qu'une fois TOUS les services notés** (règle utilisateur) : tant qu'un service
  n'a pas de note pour un étudiant, l'unité Stage est **exclue** des moyennes (bouche-trou = note implicite 0
  supprimé pour le Stage) au lieu d'être tirée vers le bas.
  - `MoyenneService::stageUniteIncomplete()` + garde dans `moyenneUnite()` → couvre `moyenneGenerale`, l'aperçu
    admin et le récapitulatif (`.docx`).
  - `BulletinService::moyenneTypeForUnite('cc')` : même garde ; la ligne Stage du bulletin affiche aussi
    `moyenneSeq = null` (« — ») tant qu'il manque un service.
  - Frontend : bandeau `Saisie des Notes` mis à jour + **icône d'avertissement** à côté du nom des étudiants
    dont le stage est incomplet ; libellé « Pris en compte dans la moyenne » de la page Stage corrigé.
  - Test verrouillant la règle : `tests/Feature/StageAveragingTest.php` (tous les services notés → moyenne
    calculée ; un service manquant → exclu ; aucun service noté → exclu).
- [x] Noms des **services** du Stage remplis depuis `word/Programme Des Stages.docx` (« Lieux des stages »).
  Données : `database/data/stage_services.json` ; migration
  `2026_10_12_000001_name_stage_controles_after_services`. Les contrôles sont **renommés en place**
  (même id → mêmes notes) : AS = 8, IA an2 = 11, IP an1/an2/an3 = 4/7/14. Aucun changement frontend :
  le select de la Grille d'Évaluation lit déjà `nom`.
- [x] `Saisie des Notes` (`GestionNotes.jsx`) : **défilement horizontal** (`overflow-x: auto`) + `minWidth`
  forcé pour les unités directes larges (Stage, > 6 contrôles) afin que les colonnes ne soient plus
  compressées/coupées sur grand écran et que la barre de défilement apparaisse réellement.
- [x] `Grille d'Évaluation du Stage` : champ `service` = composant **`FilterSelect`** (le même combobox que le
  sélecteur « Filière » de la page Filtres), alimenté par les **contrôles de l'unité Stage** (= les services)
  via `GET /api/admin/stage/services?etudiant_id=…`.
