<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;

Settings::setZipClass(Settings::PCLZIP);

$path = $argv[1];
$out = $argv[2] ?? null;

$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot open $path\n");
    exit(1);
}
$xml = $zip->getFromName('word/document.xml');
$zip->close();

$dom = new DOMDocument();
$dom->loadXML($xml);
$xp = new DOMXPath($dom);
$xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

const WNS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

$attr = function (DOMElement $el, string $name): string {
    $v = $el->getAttributeNS(WNS, $name);
    if ($v === '' && !$el->hasAttributeNS(WNS, $name)) {
        $v = $el->getAttribute($name);
    }
    return $v;
};

$noText = ($argv[4] ?? '') === 'notext';

$runSig = function (DOMElement $r) use ($xp, $attr, $noText): string {
    $t = '';
    if (!$noText) {
        foreach ($xp->query('.//w:t', $r) as $node) {
            $t .= $node->textContent;
        }
    }
    $rPr = $xp->query('./w:rPr', $r)->item(0);
    $bits = [];
    if ($rPr) {
        $fonts = $xp->query('./w:rFonts', $rPr)->item(0);
        if ($fonts) {
            $bits[] = 'f=' . ($attr($fonts, 'ascii') ?: '-') . '|' . ($attr($fonts, 'hAnsi') ?: '-');
        }
        $sz = $xp->query('./w:sz', $rPr)->item(0);
        if ($sz) {
            $bits[] = 'sz=' . $attr($sz, 'val');
        }
        $szCs = $xp->query('./w:szCs', $rPr)->item(0);
        if ($szCs) {
            $bits[] = 'szCs=' . $attr($szCs, 'val');
        }
        if ($xp->query('./w:b', $rPr)->length) {
            $bits[] = 'b';
        }
        if ($xp->query('./w:i', $rPr)->length) {
            $bits[] = 'i';
        }
        $color = $xp->query('./w:color', $rPr)->item(0);
        if ($color) {
            $bits[] = 'c=' . $attr($color, 'val');
        }
        $u = $xp->query('./w:u', $rPr)->item(0);
        if ($u) {
            $bits[] = 'u=' . $attr($u, 'val');
        }
    }
    $shown = str_replace([' ', "\t"], ['·', '→'], $t);
    return '[' . ($bits ? implode(' ', $bits) : '-') . ']' . $shown;
};

$paraSig = function (DOMElement $p) use ($xp, $attr, $runSig): string {
    $bits = [];
    $pPr = $xp->query('./w:pPr', $p)->item(0);
    if ($pPr) {
        $jc = $xp->query('./w:jc', $pPr)->item(0);
        if ($jc) {
            $bits[] = 'jc=' . $attr($jc, 'val');
        }
        $sp = $xp->query('./w:spacing', $pPr)->item(0);
        if ($sp) {
            $bits[] = 'sp(before=' . $attr($sp, 'before') . ',after=' . $attr($sp, 'after') . ',line=' . $attr($sp, 'line') . ',rule=' . $attr($sp, 'lineRule') . ')';
        }
        $ind = $xp->query('./w:ind', $pPr)->item(0);
        if ($ind) {
            $bits[] = 'ind(left=' . $attr($ind, 'left') . ',right=' . $attr($ind, 'right') . ',first=' . $attr($ind, 'firstLine') . ')';
        }
        $st = $xp->query('./w:pStyle', $pPr)->item(0);
        if ($st) {
            $bits[] = 'style=' . $attr($st, 'val');
        }
        $pb = $xp->query('.//w:br[@w:type="page"]', $pPr)->length;
        if ($pb) {
            $bits[] = 'PB-in-pPr';
        }
    }

    $out = '{' . ($bits ? implode(' ', $bits) : '') . '}';
    if ($xp->query('.//w:br[@w:type="page"]', $p)->length) {
        $out .= '<<PAGEBREAK>>';
    }
    foreach ($xp->query('./w:r', $p) as $r) {
        $out .= $runSig($r);
    }

    return $out;
};

$body = $xp->query('/w:document/w:body')->item(0);
$index = 0;
$lines = [];
foreach ($body->childNodes as $child) {
    if ($child->nodeType !== XML_ELEMENT_NODE) {
        continue;
    }
    $local = $child->localName;

    if ($local === 'p') {
        $sig = $paraSig($child);
        if ($sig === '{}' || $sig === '') {
            $index++;
            continue;
        }
        $stop = str_contains($sig, '<<PAGEBREAK>>');
        $lines[] = "P$index: $sig";
        if ($stop && ($argv[3] ?? '') !== 'all') {
            $index++;
            $lines[] = '--- first sheet ends here ---';
            break;
        }
    } elseif ($local === 'tbl') {
        $lines[] = "TBL$index START";
        $r = 0;
        foreach ($xp->query('./w:tr', $child) as $tr) {
            $h = $xp->query('./w:trPr/w:trHeight', $tr)->item(0);
            $lines[] = "  row$r h=" . ($h ? $attr($h, 'val') . ($attr($h, 'hRule') ? '/' . $attr($h, 'hRule') : '') : '-');
            $c = 0;
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $w = $xp->query('./w:tcPr/w:tcW', $tc)->item(0);
                $grid = $xp->query('./w:tcPr/w:gridSpan', $tc)->item(0);
                $va = $xp->query('./w:tcPr/w:vAlign', $tc)->item(0);
                $meta = 'w=' . ($w ? $attr($w, 'w') . '/' . $attr($w, 'type') : '-') .
                    ' span=' . ($grid ? $attr($grid, 'val') : '-') .
                    ' va=' . ($va ? $attr($va, 'val') : '-');
                $lines[] = "    c$c $meta";
                $pi = 0;
                foreach ($xp->query('./w:p', $tc) as $p) {
                    $lines[] = "      p$pi " . $paraSig($p);
                    $pi++;
                }
                $c++;
            }
            $r++;
        }
        $lines[] = "TBL$index END";
    } elseif ($local === 'sectPr') {
        $size = $xp->query('./w:pgSz', $child)->item(0);
        $mar = $xp->query('./w:pgMar', $child)->item(0);
        $lines[] = 'SECT w=' . ($size ? $attr($size, 'w') . 'x' . $attr($size, 'h') . '/' . $attr($size, 'orient') : '-') .
            ' margins=' . ($mar ? 't' . $attr($mar, 'top') . ' r' . $attr($mar, 'right') . ' b' . $attr($mar, 'bottom') . ' l' . $attr($mar, 'left') . ' hdr' . $attr($mar, 'header') . ' ftr' . $attr($mar, 'footer') : '-');
    }

    $index++;
}

$text = implode("\n", $lines) . "\n";

if ($out === null) {
    echo $text;
} else {
    file_put_contents($out, $text);
    echo "written " . strlen($text) . " bytes to $out\n";
}
