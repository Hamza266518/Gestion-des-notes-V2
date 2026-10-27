<?php
// Pour IP2 (tableau 5), compter les occurrences par (service, groupe) telles
// que le CALENDRIER les pose, pour les comparer au JSON.
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

// compter les groupes mentionnes par colonne service
$o = "IP2 (tableau 5) : occurrences par (service, groupe)\n\n";
$counts = [];
for ($c = 1; $c < count($svcs); $c++) {
    $svc = preg_replace('/\s+/u', ' ', trim($svcs[$c]));
    $counts[$svc] = [];
    for ($r = 1; $r < count($rows); $r++) {
        $cell = str_replace(["\u{2013}","\u{2014}"], ' ', (string)($rows[$r][$c] ?? ''));
        if (preg_match_all('/G\s*:\s*([A-J])/u', $cell, $m)) {
            foreach ($m[1] as $g) {
                $counts[$svc][$g] = ($counts[$svc][$g] ?? 0) + 1;
            }
        }
    }
}
foreach ($counts as $svc => $byG) {
    if (!$byG) { $o .= "$svc : (aucune occurrence)\n"; continue; }
    ksort($byG);
    $o .= "$svc : ";
    foreach ($byG as $g => $n) $o .= "$g=$n ";
    $o .= "\n";
}

// total par groupe
$o .= "\nTOTAL par groupe (calendrier):\n";
$tot = [];
foreach ($counts as $svc => $byG) foreach ($byG as $g => $n) $tot[$g] = ($tot[$g] ?? 0) + $n;
ksort($tot);
foreach ($tot as $g => $n) $o .= "  $g : $n\n";

file_put_contents(__DIR__ . '/_ip2count.txt', $o);
echo "written\n";
