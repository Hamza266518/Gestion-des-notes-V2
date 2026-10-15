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
