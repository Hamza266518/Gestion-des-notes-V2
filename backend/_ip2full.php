<?php
// Afficher le tableau 5 (IP2) entier, ligne par ligne, colonne 0 (dates) + la
// cellule Medecine pour voir la structure des dates.
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

$tbl = $xp->query('//w:tbl')->item(5);
$out = "";
$r = 0;
foreach ($xp->query('./w:tr', $tbl) as $tr) {
    $first = '';
    $cells = [];
    foreach ($xp->query('./w:tc', $tr) as $ti => $tc) {
        $txt = '';
        foreach ($xp->query('.//w:t', $tc) as $t) $txt .= $t->textContent;
        $txt = preg_replace('/\s+/u', ' ', trim($txt));
        if ($ti === 0) $first = $txt;
        $cells[] = $txt;
    }
    $out .= sprintf("R%02d | %-24s | Médecine=%-30s | PF=%-18s | CS=%s\n",
        $r, $first, substr($cells[1] ?? '', 0, 30), substr($cells[6] ?? '', 0, 18), substr($cells[7] ?? '', 0, 18));
    $r++;
}
file_put_contents(__DIR__ . '/_ip2full.txt', $out);
echo "written\n";
