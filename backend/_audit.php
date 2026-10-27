<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function docxTables(string $file): array
{
    $zip = new \PhpOffice\PhpWord\Shared\ZipArchive();
    $zip->open($file, \PhpOffice\PhpWord\Shared\ZipArchive::CREATE);
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', W);

    $tables = [];
    foreach ($xp->query('//w:tbl') as $tbl) {
        $rows = [];
        foreach ($xp->query('./w:tr', $tbl) as $tr) {
            $cells = [];
            foreach ($xp->query('./w:tc', $tr) as $tc) {
                $t = '';
                foreach ($xp->query('.//w:t', $tc) as $n) {
                    $t .= $n->textContent;
                }
                $cells[] = trim(preg_replace('/\s+/u', ' ', $t));
            }
            $rows[] = $cells;
        }
        $tables[] = $rows;
    }
    return $tables;
}

\PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);

$norm = fn ($s) => preg_replace('/\s+/', ' ', trim((string) $s));

$out = '';

// ---- 1. Fichier stagiaires 2026/2027 ----
$file = __DIR__ . '/../word/Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx';
$tables = docxTables($file);

$fichier = [];
for ($i = 0; $i < count($tables); $i++) {
    if (($tables[$i][0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }
    $meta = implode(' ', array_map(fn ($r) => implode(' ', $r), $tables[$i]));
    preg_match('/Filière\s*:\s*([^|]+?)\s*(Année|Annee)/u', $meta, $fm);
    preg_match('/Année de formation\s*:\s*([^|]+?)\s*Promotion/u', $meta, $nm);
    preg_match('/Promotion\s*:\s*([0-9\/]+)/u', $meta, $pm);

    foreach (($tables[$i + 1] ?? []) as $ri => $row) {
        if ($ri === 0) {
            continue;
        }
        $nom = trim($row[0] ?? '');
        if ($nom === '') {
            continue;
        }
        $fichier[] = [
            'filiere' => trim($fm[1] ?? '?'),
            'niveau'  => trim($nm[1] ?? '?'),
            'promo'   => trim($pm[1] ?? '?'),
            'nom'     => $nom,
            'cin'     => trim($row[1] ?? ''),
            'num'     => trim($row[2] ?? ''),
        ];
    }
}

$out .= "=== FICHIER 2026/2027 : " . count($fichier) . " stagiaires ===\n\n";

// ---- 2. Base ----
$db = DB::select('
    select e.id, e.nom_prenom, e.cin, e.nom_ar, e.cin_ar, e.numero_inscription, e.numero_inscription_ar,
           e.date_naissance, e.lieu_naissance, e.nationalite, e.date_inscription, e.status,
           g.nom as gnom, g.promotion, n.numero as niveau, f.nom as filiere, a.label as annee
    from etudiants e
    left join groupes g on g.id = e.groupe_id
    left join niveaux n on n.id = g.niveau_id
    left join annees_academiques a on a.id = e.annee_academique_id
    left join filieres f on f.id = n.filiere_id
');

$out .= "=== BASE : " . count($db) . " etudiants ===\n\n";

// ---- 3. Champs manquants / suspects ----
$out .= "=== CHAMPS VIDES OU SUSPECTS ===\n\n";

$sansCin = [];
$sansNum = [];
$sansDateN = [];
$sansLieuN = [];
$sansNationalite = [];
$sansDateInsc = [];
$cinSuspect = [];
$dateSuspect = [];

foreach ($db as $e) {
    $cin = $norm($e->cin);
    $num = $norm($e->numero_inscription);

    if ($cin === '') $sansCin[] = $e;
    if ($num === '') $sansNum[] = $e;
    if (!$e->date_naissance) $sansDateN[] = $e;
    if (trim((string) $e->lieu_naissance) === '') $sansLieuN[] = $e;
    if (trim((string) $e->nationalite) === '') $sansNationalite[] = $e;
    if (!$e->date_inscription) $sansDateInsc[] = $e;

    // CIN qui ressemble a un numero factice : suites genre AB1000xx, AB123456...
    if ($cin !== '' && preg_match('/^(AB|CD|EF|GH|IJ|KL|MN|OP|QR|ST)\d{5,6}$/', $cin)) {
        $cinSuspect[] = $e;
    }

    // Date de naissance par defaut
    if ($e->date_naissance && in_array($e->date_naissance, ['2000-01-01'], true)) {
        $dateSuspect[] = $e;
    }
}

$out .= sprintf("  sans CIN            : %d\n", count($sansCin));
$out .= sprintf("  sans numero inscrip.: %d\n", count($sansNum));
$out .= sprintf("  sans date naissance : %d\n", count($sansDateN));
$out .= sprintf("  sans lieu naissance : %d\n", count($sansLieuN));
$out .= sprintf("  sans nationalite    : %d\n", count($sansNationalite));
$out .= sprintf("  sans date inscription: %d\n", count($sansDateInsc));
$out .= sprintf("  CIN factices (AA123456/AB1000xx): %d\n", count($cinSuspect));
$out .= sprintf("  date naissance 2000-01-01 : %d\n", count($dateSuspect));

// ---- 4. Correspondance base <-> fichier 2026/2027 ----
$byNum = [];
foreach ($db as $e) {
    $byNum[preg_replace('/\s+/', '', mb_strtoupper($e->numero_inscription))] = $e;
}

$inFichierNotDb = [];
foreach ($fichier as $f) {
    $n = preg_replace('/\s+/', '', mb_strtoupper($f['num']));
    if ($n === '') continue;
    if (!isset($byNum[$n])) {
        $inFichierNotDb[] = $f;
    }
}

$out .= "\n=== FICHIER 2026/2027 -> BASE ===\n";
$out .= "  Dans le fichier mais PAS en base : " . count($inFichierNotDb) . " / " . count($fichier) . "\n";

// ---- 5. La liste des CIN factices, en detail ----
$out .= "\n=== CIN FACTICES EN DETAIL (" . count($cinSuspect) . ") ===\n";
foreach ($cinSuspect as $e) {
    $out .= sprintf("  id=%-5d %-30s cin=%-14s num=%-14s %-22s niv%-3s\n",
        $e->id, $e->nom_prenom, $e->cin, $e->numero_inscription ?? '-', $e->filiere ?? '-', $e->niveau ?? '-');
}

file_put_contents(__DIR__ . '/_audit.txt', $out);
echo "written\n";
