<?php

/**
 * One-off: bring the evaluation template's identity block back to the origin's
 * own rendering (values regular, name/number at 11pt, values right after the
 * colon) and align the new "Pratique exécutée / Service" row with the row above.
 */

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\ZipArchive;

Settings::setZipClass(Settings::PCLZIP);

const WNS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const XMLNS = 'http://www.w3.org/XML/1998/namespace';

$path = __DIR__ . '/resources/stage/grille_evaluation.docx';
$backup = sys_get_temp_dir() . '/grille_evaluation.backup.docx';
copy($path, $backup);

$zip = new ZipArchive();
if ($zip->open($path, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "cannot open $path\n");
    exit(1);
}

$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    $entries[$name] = $zip->getFromName($name);
}
$zip->close();

if (!isset($entries['word/document.xml'])) {
    fwrite(STDERR, "no document.xml\n");
    exit(1);
}

$dom = new DOMDocument();
$dom->preserveWhiteSpace = true;
if (!@$dom->loadXML($entries['word/document.xml'])) {
    fwrite(STDERR, "document.xml is not valid XML\n");
    exit(1);
}

$xp = new DOMXPath($dom);
$xp->registerNamespace('w', WNS);

$children = static function (DOMElement $el, string $local): array {
    $out = [];
    foreach ($el->childNodes as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && $node->localName === $local) {
            $out[] = $node;
        }
    }

    return $out;
};

$table = $xp->query('/w:document/w:body/w:tbl')->item(0);
$rows = $children($table, 'tr');

if (count($rows) !== 5) {
    fwrite(STDERR, 'expected 5 identity rows, got ' . count($rows) . "\n");
    exit(1);
}

/**
 * @param array{0:int,1:int,2:int,3:string} $edit  row, cell, run, new text
 */
$apply = static function (array $edit) use ($children, $rows): string {
    [$r, $c, $i, $text] = $edit;

    $cells = $children($rows[$r], 'tc');
    $paras = $children($cells[$c], 'p');
    $runs = $children($paras[0], 'r');
    $run = $runs[$i] ?? null;

    if ($run === null) {
        throw new RuntimeException("missing run row=$r cell=$c run=$i");
    }

    $t = null;
    foreach ($run->childNodes as $node) {
        if ($node->nodeType === XML_ELEMENT_NODE && $node->localName === 't') {
            $t = $node;
            break;
        }
    }

    if ($t === null) {
        throw new RuntimeException("run without w:t row=$r cell=$c run=$i");
    }

    $before = $t->textContent;
    $t->textContent = $text;
    $t->setAttributeNS(XMLNS, 'xml:space', 'preserve');

    return sprintf(
        'row %d cell %d run %d: %-28s -> %s',
        $r,
        $c,
        $i,
        var_export($before, true),
        var_export($text, true)
    );
};

// Where the origin writes its own value, the placeholder now sits: same run,
// same size, same weight. Everything else (labels, tabs, colons) is untouched.
$edits = [
    [0, 0, 2, ' '],                      // Nom et prénom: back to a plain space
    [0, 0, 3, '${nom_prenom}'],          // the 11pt run, like the origin's name
    [0, 1, 2, ' '],
    [0, 1, 3, ' ${annee_scolaire}'],     // regular, not the bold label run
    [1, 0, 2, ' '],
    [1, 0, 3, '${numero_inscription}'],  // the 11pt run, like the origin's number
    [1, 1, 2, ' '],
    [1, 1, 3, ' ${formation}'],
    [2, 0, 6, '  '],
    [2, 0, 7, '${filiere}'],
    [3, 0, 3, ' '],
    [3, 0, 4, ' ${section}'],
    [3, 1, 2, '  ${lieu}'],              // right after the colon, like Durée
    [4, 0, 2, ' :'],                     // colon back in line with Section's
    [4, 0, 3, ' '],
    [4, 0, 4, ' ${pratique_executee}'],
    [4, 1, 0, 'Service' . str_repeat(' ', 23)],
    [4, 1, 2, '  ${service}'],
];

foreach ($edits as $edit) {
    echo $apply($edit) . "\n";
}

// Keep the file's own XML declaration, DOM would reword it.
$original = $entries['word/document.xml'];
$updated = $dom->saveXML();
if (preg_match('/^<\?xml[^?]*\?>/', $original, $old) === 1
    && preg_match('/^<\?xml[^?]*\?>/', $updated, $new) === 1) {
    $updated = $old[0] . substr($updated, strlen($new[0]));
}
$entries['word/document.xml'] = $updated;

$out = sys_get_temp_dir() . '/grille_evaluation_fixed.docx';
@unlink($out);

$zip = new ZipArchive();
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
foreach ($entries as $name => $data) {
    if ($data === false || $data === '' || substr($name, -1) === '/') {
        continue;
    }
    $zip->addFromString($name, $data);
}
$zip->close();

copy($out, $path);
@unlink($out);

echo "\nwritten $path\nbackup $backup\n";
