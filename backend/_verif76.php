<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$pdf = json_decode(file_get_contents(__DIR__ . '/_laureats_parsed.json'), true);

$norm = fn ($s) => preg_replace('/\s+/', '', mb_strtoupper(trim((string) $s)));
$normName = function (?string $s): string {
    $s = mb_strtoupper(trim((string) $s));
    $s = str_replace(['-', "'", '’'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
};

// Index la base par numero normalise
$dbAll = DB::table('etudiants')->get();
$byNum = [];
foreach ($dbAll as $e) {
    $byNum[$norm($e->numero_inscription)] = $e;
}

// Normalisation des dates au format Y-m-d
$toYmd = function (?string $d): ?string {
    if (!$d) return null;
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($d), $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return trim($d);
};

$out = "=== VERIFICATION DES 76 LAUREATS : PDF vs BASE ===\n\n";
$out .= "champs compares : numero, cin, date_naissance, date_inscription\n\n";

$okCount = 0;
$mismatches = [];
$absents = [];

foreach ($pdf as $p) {
    $numKey = $norm($p['num']);
    if (!isset($byNum[$numKey])) {
        $absents[] = $p['num'];
        continue;
    }
    $e = $byNum[$numKey];

    $diffs = [];

    // CIN
    if ($norm($e->cin) !== $norm($p['cin'])) {
        $diffs[] = sprintf("CIN            pdf=%-12s base=%-12s", $p['cin'], $e->cin ?? '-');
    }

    // Date de naissance
    $pdfN = $toYmd($p['dnaiss']);
    $dbN  = $e->date_naissance ? substr($e->date_naissance, 0, 10) : null;
    if ($pdfN !== $dbN) {
        $diffs[] = sprintf("DATE_NAISSANCE pdf=%-12s base=%-12s", $p['dnaiss'] ?? '-', $dbN ?? '-');
    }

    // Date d'inscription
    $pdfI = $toYmd($p['dinsc']);
    $dbI  = $e->date_inscription ? substr($e->date_inscription, 0, 10) : null;
    if ($pdfI !== $dbI) {
        $diffs[] = sprintf("DATE_INSCRIPTION pdf=%-12s base=%-12s", $p['dinsc'] ?? '-', $dbI ?? '-');
    }

    if ($diffs === []) {
        $okCount++;
    } else {
        $mismatches[] = [
            'num'   => $p['num'],
            'id'    => $e->id,
            'nom'   => $e->nom_prenom,
            'pdf'   => $p['nom_before'] ?? '',
            'diffs' => $diffs,
        ];
    }
}

$out .= sprintf("  CONFORMES        : %d / 76\n", $okCount);
$out .= sprintf("  AVEC ECART       : %d\n", count($mismatches));
$out .= sprintf("  ABSENTS DE LA BASE: %d\n\n", count($absents));

if ($mismatches !== []) {
    $out .= "=== DETAIL DES ECARTS ===\n\n";
    foreach ($mismatches as $m) {
        $out .= sprintf("  %-10s id=%-5d %-28s (pdf: %s)\n", $m['num'], $m['id'], $m['nom'], $m['pdf']);
        foreach ($m['diffs'] as $d) {
            $out .= "        " . $d . "\n";
        }
        $out .= "\n";
    }
}

if ($absents !== []) {
    $out .= "=== ABSENTS ===\n";
    foreach ($absents as $a) {
        $out .= "  $a\n";
    }
}

// Recapitulatif du type d'ecart
$parType = ['CIN' => 0, 'DATE_NAISSANCE' => 0, 'DATE_INSCRIPTION' => 0];
foreach ($mismatches as $m) {
    foreach ($m['diffs'] as $d) {
        foreach ($parType as $k => $_) {
            if (str_starts_with($d, $k)) {
                $parType[$k]++;
            }
        }
    }
}
$out .= "\n=== ECARTS PAR TYPE ===\n";
foreach ($parType as $k => $n) {
    $out .= sprintf("  %-20s %d\n", $k, $n);
}

file_put_contents(__DIR__ . '/_verif76.txt', $out);
echo "written\n";
