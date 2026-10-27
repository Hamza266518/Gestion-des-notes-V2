<?php
// Lister les services du calendrier (tableau 5 = IP2) et ceux du JSON (IP niv2).
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
$header = [];
$tr = $xp->query('./w:tr', $tbl)->item(0);
foreach ($xp->query('./w:tc', $tr) as $tc) {
    $txt = '';
    foreach ($xp->query('.//w:t', $tc) as $t) $txt .= $t->textContent;
    $header[] = preg_replace('/\s+/u', ' ', trim($txt));
}

$out = "CALENDRIER tableau 5 (IP2) header:\n";
foreach ($header as $i => $h) $out .= "  [$i] $h\n";

// JSON IP2 services (unique, ordre)
$json = json_decode(file_get_contents(__DIR__ . '/database/data/stage_rotations.json'), true);
foreach ($json as $b) {
    if ($b['filiere'] === 'Infirmier Polyvalent' && $b['niveau'] == 2) {
        $out .= "\nJSON IP niv2 services (groupe A, ordre):\n";
        foreach ($b['groupes']['A'] as $i => $s) {
            $out .= "  [$i] {$s['service']}\n";
        }
        $uniq = [];
        foreach ($b['groupes'] as $g => $steps) foreach ($steps as $s) $uniq[$s['service']] = true;
        $out .= "\nJSON IP niv2 services UNIQUES (" . count($uniq) . "):\n";
        foreach (array_keys($uniq) as $u) $out .= "  - $u\n";
    }
}
file_put_contents(__DIR__ . '/_svclist.txt', $out);
echo "written\n";
