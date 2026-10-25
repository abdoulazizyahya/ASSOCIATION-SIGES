<?php
// tests/lancer.php — lance tous les tests (poste local) avant un commit ou un zip :
//   php tests/lancer.php            tous les tests
//   php tests/lancer.php edt        seulement ceux dont le nom contient « edt »
// Chaque test tourne dans son propre processus ; code de sortie 1 si un échoue.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$filtre = $argv[1] ?? '';
$php = escapeshellarg(PHP_BINARY);
$echecs = [];
foreach (glob(__DIR__ . '/t_*.php') as $f) {
    $nom = basename($f, '.php');
    if ($filtre !== '' && !str_contains($nom, $filtre)) continue;
    echo "▶ $nom\n";
    $t = microtime(true);
    passthru("$php " . escapeshellarg($f) . ' 2>&1', $rc);
    printf("  (%.1f s)\n", microtime(true) - $t);
    if ($rc !== 0) $echecs[] = $nom;
}
echo $echecs ? "\nÉCHEC : " . implode(', ', $echecs) . "\n" : "\nTous les tests sont passés.\n";
exit($echecs ? 1 : 0);
