<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;

Settings::setZipClass(Settings::PCLZIP);

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function cellText(DOMXPath $xp, DOMElement $tc): string
{
    $parts = [];
    foreach ($xp->query('./w:p', $tc) as $p) {
        $t = '';
        foreach ($xp->query('.//w:t', $p) as $n) {
            $t .= $n->textContent;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t !== '') {
            $parts[] = $t;
        }
    }
    return trim(implode(' | ', $parts));
}

$files = [
    __DIR__ . '/../word/Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx',
    __DIR__ . '/../word/Liste des stagiaires IP 2 2025-2026.docx',
];

$out = '';

foreach ($files as $file) {
    $out .= "===== " . basename($file) . " =====\n";

    if (!is_file($file)) {
        $out .= "  (introuvable)\n\n";
        continue;
    }

    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W);

    // Paragraphes hors tableaux (titres).
    foreach ($xp->query('//w:body/w:p') as $p) {
        $t = '';
        foreach ($xp->query('.//w:t', $p) as $n) {
            $t .= $n->textContent;
        }
        $t = trim(preg_replace('/\s+/u', ' ', $t));
        if ($t !== '') {
            $out .= "  P: $t\n";
        }
    }

    foreach ($xp->query('//w:tbl') as $ti => $tbl) {
        $rows = $xp->query('./w:tr', $tbl);
        $out .= "  -- Table $ti : " . $rows->length . " lignes --\n";
        foreach ($rows as $ri => $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $cells[] = cellText($xp, $tc);
            }
            $out .= sprintf("    r%-3d %s\n", $ri, implode(' | ', $cells));
        }
    }

    $out .= "\n";
}

file_put_contents(__DIR__ . '/_listes.txt', $out);
echo "written " . strlen($out) . " bytes\n";
