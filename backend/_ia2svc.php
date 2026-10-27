<?php
// Services uniques IA2 (calendrier tableau 9) vs JSON IA niv2.
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

$tbl = $xp->query('//w:tbl')->item(9);
$header = [];
$tr = $xp->query('./w:tr', $tbl)->item(0);
foreach ($xp->query('./w:tc', $tr) as $tc) {
    $txt = '';
    foreach ($xp->query('.//w:t', $tc) as $t) $txt .= $t->textContent;
    $header[] = preg_replace('/\s+/u', ' ', trim($txt));
}
$o = "CALENDRIER tableau 9 (IA2) header (" . (count($header)) . " col):\n";
foreach ($header as $i => $h) $o .= "  [$i] $h\n";

$json = json_decode(file_get_contents(__DIR__ . '/database/data/stage_rotations.json'), true);
foreach ($json as $b) {
    if ($b['filiere'] === 'Infirmier Auxiliaire' && $b['niveau'] == 2) {
        $o .= "\nJSON IA niv2 groupe A (" . count($b['groupes']['A']) . " etapes):\n";
        foreach ($b['groupes']['A'] as $i => $s) $o .= "  [$i] {$s['service']}\n";
        $uniq = [];
        foreach ($b['groupes'] as $g => $steps) foreach ($steps as $s) $uniq[$s['service']] = true;
        $o .= "\nJSON IA niv2 services UNIQUES (" . count($uniq) . "): " . implode(', ', array_keys($uniq)) . "\n";
    }
}
file_put_contents(__DIR__ . '/_ia2svc.txt', $o);
echo "written\n";
