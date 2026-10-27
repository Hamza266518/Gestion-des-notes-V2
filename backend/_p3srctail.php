<?php
// Comparer les DERNIERES periodes source (IP3) vs les dernieres etapes JSON.
// Objectif : la rotation IP3 se termine-t-elle, et "Stage optionnel" est-il inclus ?
require __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpWord\Settings;
Settings::setZipClass(Settings::PCLZIP);

$path = __DIR__ . '/../word/CALENDRIER Prévisionnelde realisation.docx';
$zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
$zip->open($path);
$xml = (string) $zip->getFromName('word/document.xml');
$zip->close();

$dom = new DOMDocument();
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
$tbl = $xp->query('//w:tbl')->item(1);

$grid = [];
foreach ($xp->query('./w:tr', $tbl) as $tr) {
    $row = [];
    foreach ($xp->query('./w:tc', $tr) as $tc) {
        $txt = '';
        foreach ($xp->query('.//w:t', $tc) as $t) { $txt .= $t->textContent; }
        $txt = trim(preg_replace('/\s+/u', ' ', $txt));
        $span = 1;
        foreach ($xp->query('./w:tcPr/w:gridSpan', $tc) as $gs) { $span = (int) $gs->getAttribute('w:val'); }
        for ($k = 0; $k < $span; $k++) { $row[] = $k === 0 ? $txt : ''; }
    }
    $grid[] = $row;
}
$width = 0; foreach ($grid as $r) { $width = max($width, count($r)); }
foreach ($grid as &$r) { $r = array_pad($r, $width, ''); } unset($r);

$out = "=== 8 dernieres rangees source IP3 (col0..col15) ===\n";
$rows = count($grid);
for ($ri = max(0, $rows - 8); $ri < $rows; $ri++) {
    $out .= sprintf("R%-2d : %s\n", $ri, implode(' | ', array_map(fn($c) => $c === '' ? '·' : $c, $grid[$ri])));
}
file_put_contents(__DIR__ . '/_p3_src_tail.txt', $out);
echo "written\n";
