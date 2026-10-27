<?php
// Suivre le groupe A dans le tableau 5 (IP2) : pour chaque ligne de date,
// quel service porte G:A ?
require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpWord\Settings;
Settings::setZipClass(Settings::PCLZIP);
$path = __DIR__ . '/../word/CALENDRIER Prévisionnelde realisation.docx';
$zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
$zip->open($path);
$doc = (string) $zip->getFromName('word/document.xml');
$zip->close();
$dom = new DOMDocument(); $dom->loadXML($doc);
$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

function rows(DOMXPath $xp, $i) {
    $tbl = $xp->query('//w:tbl')->item($i);
    $out = [];
    foreach ($xp->query('./w:tr', $tbl) as $tr) {
        $cells = [];
        foreach ($xp->query('./w:tc', $tr) as $tc) {
            $txt = '';
            foreach ($xp->query('.//w:t', $tc) as $t) $txt .= $t->textContent;
            $cells[] = preg_replace('/\s+/u', ' ', trim($txt));
        }
        $out[] = $cells;
    }
    return $out;
}

$rows = rows($xp, 5);
$svcs = $rows[0];
$G = 'A';
$o = "Groupe $G dans IP2 (tableau 5), service par ligne de date:\n";
for ($r = 1; $r < count($rows); $r++) {
    $date = $rows[$r][0];
    if ($date === '') continue; // ligne de lieu
    $found = [];
    for ($c = 1; $c < count($svcs); $c++) {
        $cell = str_replace(["\u{2013}","\u{2014}"], ' ', (string)($rows[$r][$c] ?? ''));
        if (preg_match('/G\s*:\s*' . $G . '\b/u', $cell)) {
            $found[] = preg_replace('/\s+/u', ' ', trim($svcs[$c]));
        }
    }
    $o .= sprintf("R%02d %-24s : %s\n", $r, trim($date), $found ? implode(', ', $found) : '(aucun)');
}
file_put_contents(__DIR__ . '/_ga.txt', $o);
echo "written\n";
