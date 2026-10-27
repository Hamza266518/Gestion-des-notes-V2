<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;

Settings::setZipClass(Settings::PCLZIP);

$path = $argv[1];
$out = $argv[2];

$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot open $path\n");
    exit(1);
}
$xml = $zip->getFromName('word/document.xml');
$zip->close();

$dom = new DOMDocument();
$dom->preserveWhiteSpace = true;
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

$tbl = $xp->query('/w:document/w:body/w:tbl')->item(0);

$lines = [];
$tr = 0;
foreach ($xp->query('./w:tr', $tbl) as $row) {
    $lines[] = "===== ROW $tr =====";
    $c = 0;
    foreach ($xp->query('./w:tc', $row) as $cell) {
        $lines[] = "  --- cell $c ---";
        $flat = function ($node) {
            $xml = $node->ownerDocument->saveXML($node);
            return preg_replace('/\s+xmlns(:\w+)?="[^"]*"/', '', $xml);
        };
        $lines[] = '  trPr: ' . ($xp->query('./w:trPr', $row)->length ? $flat($xp->query('./w:trPr', $row)->item(0)) : '(none)');
        $lines[] = '  tcPr: ' . $flat($xp->query('./w:tcPr', $cell)->item(0));
        foreach ($xp->query('./w:p', $cell) as $p) {
            $pPr = $xp->query('./w:pPr', $p)->item(0);
            $lines[] = '    pPr: ' . ($pPr ? $flat($pPr) : '(none)');
            $k = 0;
            foreach ($xp->query('./w:r', $p) as $r) {
                $lines[] = '      r' . $k . ': ' . $flat($r);
                $k++;
            }
        }
        $c++;
    }
    $tr++;
}

file_put_contents($out, implode("\n", $lines) . "\n");
echo "written " . count($lines) . " lines to $out\n";
