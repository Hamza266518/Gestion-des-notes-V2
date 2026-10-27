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

$file = __DIR__ . '/../word/Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx';
$tables = docxTables($file);

$out = "=== DECOMPTE DE TOUS LES CHIFFRES POSSIBLES ===\n\n";

// 1) tous les blocs, effectif brut (lignes non vides), y compris lignes vides
$totalRows = 0;
$totalNonVides = 0;
$totalAvecNum = 0;

for ($i = 0; $i < count($tables); $i++) {
    if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }

    $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));
    preg_match('/Filière\s*:\s*([^|]+?)\s*(Année|Annee)/u', $meta, $fm);
    preg_match('/Année de formation\s*:\s*([^|]+?)\s*Promotion/u', $meta, $nm);

    $rows = $tables[$i + 1] ?? [];
    $brut = count($rows) - 1;          // sans l'en-tete
    $nonVides = 0;
    $avecNum = 0;

    foreach ($rows as $ri => $row) {
        if ($ri === 0) {
            continue;
        }
        $nom = trim($row[0] ?? '');
        $num = trim($row[2] ?? '');
        if ($nom !== '') {
            $nonVides++;
        }
        if ($num !== '') {
            $avecNum++;
        }
    }

    $totalRows += $brut;
    $totalNonVides += $nonVides;
    $totalAvecNum += $avecNum;

    $out .= sprintf(
        "  %-22s %-14s | lignes brutes: %-3d | noms non vides: %-3d | avec numero: %-3d\n",
        trim($fm[1] ?? '?'), trim($nm[1] ?? '?'), $brut, $nonVides, $avecNum
    );
}

$out .= sprintf("\n  TOTAUX : brutes=%d  nonVides=%d  avecNumero=%d\n", $totalRows, $totalNonVides, $totalAvecNum);

// 2) effectif par filiere (toutes annees confondues)
$out .= "\n=== PAR FILIERE (toutes annees) ===\n";
$parFil = [];
for ($i = 0; $i < count($tables); $i++) {
    if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }
    $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));
    preg_match('/Filière\s*:\s*([^|]+?)\s*(Année|Annee)/u', $meta, $fm);
    $fil = trim($fm[1] ?? '?');

    $n = 0;
    foreach (($tables[$i + 1] ?? []) as $ri => $row) {
        if ($ri > 0 && trim($row[0] ?? '') !== '') {
            $n++;
        }
    }
    $parFil[$fil] = ($parFil[$fil] ?? 0) + $n;
}
foreach ($parFil as $f => $n) {
    $out .= sprintf("  %-24s %d\n", $f, $n);
}

// 3) effectif par section
$out .= "\n=== PAR SECTION ===\n";
$parSec = [];
for ($i = 0; $i < count($tables); $i++) {
    if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }
    $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));
    preg_match('/Section\s*:\s*([^|]+?)\s*(Année|Annee)/u', $meta, $sm);
    $sec = trim($sm[1] ?? '?');

    $n = 0;
    foreach (($tables[$i + 1] ?? []) as $ri => $row) {
        if ($ri > 0 && trim($row[0] ?? '') !== '') {
            $n++;
        }
    }
    $parSec[$sec] = ($parSec[$sec] ?? 0) + $n;
}
foreach ($parSec as $s => $n) {
    $out .= sprintf("  %-24s %d\n", $s, $n);
}

// 4) les combinaisons qui donnent 76
$out .= "\n=== RECHERCHE DE '76' ===\n";
$out .= sprintf("  Aide-Soignant 1ere ........ %d\n", $parFil['Aide-Soignant'] ?? 0);
$out .= sprintf("  AS + IA (Qualification+Tech) ?\n");

// Qualification = Aide-Soignant ? verifions
$q = 0;
$t = 0;
for ($i = 0; $i < count($tables); $i++) {
    if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }
    $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));
    preg_match('/Section\s*:\s*([^|]+?)\s*(Année|Annee)/u', $meta, $sm);
    $sec = trim($sm[1] ?? '?');
    $n = 0;
    foreach (($tables[$i + 1] ?? []) as $ri => $row) {
        if ($ri > 0 && trim($row[0] ?? '') !== '') {
            $n++;
        }
    }
    if (mb_stripos($sec, 'Qualification') !== false) {
        $q += $n;
    }
    if (mb_stripos($sec, 'Technicien') !== false) {
        $t += $n;
    }
}
$out .= sprintf("  Section Qualification (AS) ... %d\n", $q);
$out .= sprintf("  Section Technicien (IA+IP) .. %d\n", $t);

file_put_contents(__DIR__ . '/_count76.txt', $out);
echo "written\n";
