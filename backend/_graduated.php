<?php

require __DIR__ . '/vendor/autoload.php';

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function docxTables(string $file): array
{
    $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
    $zip->open($file, \PhpOffice\PhpWord\Shared\ZipArchive::CREATE);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W);

    $tables = [];
    foreach ($xp->query('//w:tbl') as $tbl) {
        $rows = [];
        foreach ($xp->query('./w:tr', $tbl) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $t = '';
                foreach ($xp->query('.//w:t', $tc) as $n) {
                    $t .= $n->textContent;
                }
                $cells[] = trim(preg_replace('/\s+/u', ' ', $t));
            }
            $rows[] = $cells;
        }
        $tables[] = $rows;
    }
    return $tables;
}

\PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);

$out = "=== TOUS LES BLOCS DU FICHIER, AVEC ANNEE SCOLAIRE + ANNEE DE FORMATION ===\n\n";

foreach ([
    'Fichier 2026-2027' => __DIR__ . '/../word/Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx',
    'Fichier IP2 2025-2026' => __DIR__ . '/../word/Liste des stagiaires IP 2 2025-2026.docx',
] as $label => $file) {
    $out .= "########## $label ##########\n";
    if (!is_file($file)) {
        $out .= "  (introuvable)\n\n";
        continue;
    }

    $tables = docxTables($file);

    for ($i = 0; $i < count($tables); $i++) {
        if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
            continue;
        }

        $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));

        preg_match('/Filière\s*:\s*([^|]+?)\s*\|/u', $meta . ' |', $fm);
        preg_match('/Année Scolaire\s*:\s*([0-9\/]+)/u', $meta, $am);
        preg_match('/Année de formation\s*:\s*([^|]+?)\s*Promotion/u', $meta, $nm);
        preg_match('/Promotion\s*:\s*([0-9\/]+)/u', $meta, $pm);

        $nb = 0;
        foreach (($tables[$i + 1] ?? []) as $ri => $row) {
            if ($ri === 0) {
                continue;
            }
            if (trim($row[0] ?? '') !== '') {
                $nb++;
            }
        }

        $out .= sprintf(
            "  %-24s | formation: %-12s | scolaire: %-10s | promo: %-10s | %d etudiants\n",
            trim($fm[1] ?? '?'),
            trim($nm[1] ?? '?'),
            trim($am[1] ?? '?'),
            trim($pm[1] ?? '?'),
            $nb
        );
    }
    $out .= "\n";
}

// Structure reelle des filieres d'apres le fichier
$out .= "=== STRUCTURE REELLE DES FILIERES (d'apres le fichier) ===\n\n";
$out .= "  Aide-Soignant          : 1ere annee seulement  -> 1 an  -> diplome apres 1ere\n";
$out .= "  Infirmier Auxiliaire   : 1ere, 2eme           -> 2 ans -> diplome apres 2eme\n";
$out .= "  Infirmier Polyvalent   : 1ere, 2eme, 3eme     -> 3 ans -> diplome apres 3eme\n";

file_put_contents(__DIR__ . '/_graduated.txt', $out);
echo "written\n";
