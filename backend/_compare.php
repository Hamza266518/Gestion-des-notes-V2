<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

// --- Lire le fichier docx des stagiaires ---
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

$file = __DIR__ . '/../word/Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx';
$tables = docxTables($file);

// --- Construire la liste attendue ---
$attendu = [];   // ['filiere'=>, 'niveau'=>, 'promo'=>, 'nom'=>, 'cin'=>, 'num'=>]

for ($i = 0; $i < count($tables); $i++) {
    $head = $tables[$i];
    if (($head[0][0] ?? '') !== 'Liste stagiaire') {
        continue;
    }

    $meta = implode(' | ', array_map(fn ($r) => implode(' | ', $r), $head));

    if (!preg_match('/Filière\s*:\s*([^|]+)/u', $meta, $fm)) {
        continue;
    }
    if (!preg_match('/Année de formation\s*:\s*([^|]+)/u', $meta, $nm)) {
        continue;
    }
    if (!preg_match('/Promotion\s*:\s*([^|]+)/u', $meta, $pm)) {
        continue;
    }

    $filiere = trim($fm[1]);
    $niveauRaw = trim($nm[1]);
    $promo = trim($pm[1]);

    // "2ème année", "1ère année", "1ère Année" -> numero
    $numero = null;
    if (preg_match('/(\d+)/', $niveauRaw, $m)) {
        $numero = (int) $m[1];
    }

    $rows = $tables[$i + 1] ?? [];
    foreach ($rows as $ri => $row) {
        if ($ri === 0) {
            continue;   // en-tete
        }
        $nom = trim($row[0] ?? '');
        if ($nom === '') {
            continue;
        }
        $attendu[] = [
            'filiere' => $filiere,
            'numero'  => $numero,
            'promo'   => $promo,
            'nom'     => $nom,
            'cin'     => trim($row[1] ?? ''),
            'num'     => trim($row[2] ?? ''),
        ];
    }
}

// --- Lire la base de l'application ---
$dbEtudiants = [];
foreach (DB::select('
        select e.id, e.nom_prenom, e.cin, e.numero_inscription, e.annee_academique_id,
               g.id as gid, g.nom as gnom, g.promotion,
               n.numero as niveau, f.nom as filiere,
               a.label as annee
        from etudiants e
        join groupes g on g.id = e.groupe_id
        join niveaux n on n.id = g.niveau_id
        join annees_academiques a on a.id = e.annee_academique_id
        left join filieres f on f.id = n.filiere_id
    ') as $r) {
    $dbEtudiants[] = $r;
}

$out = "=== FICHIER : " . count($attendu) . " stagiaires ===\n";
$parBloc = [];
foreach ($attendu as $a) {
    $k = sprintf('%-22s %s annee  promo %s', $a['filiere'], $a['numero'] ?? '?', $a['promo']);
    $parBloc[$k] = ($parBloc[$k] ?? 0) + 1;
}
foreach ($parBloc as $k => $n) {
    $out .= sprintf("  %-48s %d\n", $k, $n);
}

$out .= "\n=== BASE (application) : " . count($dbEtudiants) . " etudiants ===\n";
$parBlocDb = [];
foreach ($dbEtudiants as $e) {
    $k = sprintf('%-22s %s annee  promo %s  [%s]',
        $e->filiere ?? '?', $e->niveau, $e->promotion ?? '-', $e->annee);
    $parBlocDb[$k] = ($parBlocDb[$k] ?? 0) + 1;
}
foreach ($parBlocDb as $k => $n) {
    $out .= sprintf("  %-60s %d\n", $k, $n);
}

// --- Comparer par numero_inscription ---
$dbByNum = [];
foreach ($dbEtudiants as $e) {
    $dbByNum[mb_strtoupper(trim((string) $e->numero_inscription))] = $e;
}

$out .= "\n=== COMPARAISON (par numero d'inscription) ===\n";
$inFileNotDb = [];
foreach ($attendu as $a) {
    $num = mb_strtoupper(trim($a['num']));
    if ($num === '') {
        continue;
    }
    if (!isset($dbByNum[$num])) {
        $inFileNotDb[] = $a;
    }
}

$out .= "Dans le fichier mais PAS dans la base : " . count($inFileNotDb) . "\n";
foreach ($inFileNotDb as $a) {
    $out .= sprintf("   %-30s %-14s %-10s %s annee promo %s\n",
        $a['nom'], $a['cin'], $a['num'], $a['filiere'], $a['numero'] ?? '?', $a['promo']);
}

$inDbNotFile = [];
foreach ($dbEtudiants as $e) {
    $num = mb_strtoupper(trim((string) $e->numero_inscription));
    $found = false;
    foreach ($attendu as $a) {
        if (mb_strtoupper(trim($a['num'])) === $num && $num !== '') {
            $found = true;
            break;
        }
    }
    if (!$found) {
        $inDbNotFile[] = $e;
    }
}

$out .= "\nDans la base mais PAS dans le fichier : " . count($inDbNotFile) . "\n";
foreach ($inDbNotFile as $e) {
    $out .= sprintf("   %-30s %-14s %-12s %s annee %s  [%s]\n",
        $e->nom_prenom, $e->cin ?? '-', $e->numero_inscription ?? '-', $e->filiere ?? '?',
        $e->niveau, $e->annee);
}

file_put_contents(__DIR__ . '/_compare.txt', $out);
echo "written\n";
