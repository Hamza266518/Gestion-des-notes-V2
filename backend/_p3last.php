<?php
// Dernieres etapes de chaque groupe IP3 + date de fin globale.
$json = json_decode(file_get_contents(__DIR__ . '/database/data/stage_rotations.json'), true);
$ip3 = null;
foreach ($json as $s) if ($s['filiere'] === 'Infirmier Polyvalent' && (int) $s['niveau'] === 3) $ip3 = $s;

$out = "";
foreach ($ip3['groupes'] as $G => $steps) {
    $last = end($steps);
    $out .= sprintf("G:%-2s -> %d etapes | derniere: %-24s @ %-12s [%s -> %s]\n",
        $G, count($steps), $last['service'], $last['lieu'] === '' ? '(vide)' : $last['lieu'],
        $last['debut'], $last['fin']);
}
file_put_contents(__DIR__ . '/_p3_last.txt', $out);
echo "written\n";
