<?php
// Zip de mise à jour : construit avec son manifeste, accepté tel quel, refusé
// dès qu'un fichier est altéré ou ajouté, ou qu'un chemin est dangereux.
require __DIR__ . '/_amorce.php';
require_once 'bd/lib/mise_a_jour.php';

$zip = sys_get_temp_dir() . '/siges_test_maj.zip';
$depart = trim((string) shell_exec('git rev-list -n1 HEAD -- noyau')) . '~1';   // dernier commit qui touche l'application
exec(escapeshellarg(PHP_BINARY) . ' deploy/outils/construire_zip.php ' . escapeshellarg($depart) . ' ' . escapeshellarg(str_replace('\\', '/', $zip)) . ' 2>&1', $sortie, $rc);
egal(0, $rc, 'construire_zip.php sans erreur');
$an = maj_analyser($zip);
ok($an['ok'] && $an['verifie'], 'zip construit : accepté, empreintes vérifiées (' . count($an['fichiers']) . ' fichiers)');

$z = new ZipArchive(); $z->open($zip);
$premier = array_key_first($an['fichiers']);
$z->addFromString($premier, "<?php // altéré\n");
$z->addFromString('noyau/ajout.php', "<?php\n");
$z->close();
$an = maj_analyser($zip);
ok(!$an['ok'] && preg_grep('/altéré/', $an['erreurs']) && preg_grep('/absent du manifeste/', $an['erreurs']), 'fichier altéré et fichier ajouté : zip refusé');

foreach (['../index.php' => 'remontée', 'config/config.local.php' => 'config serveur', '.htaccess' => 'fichier caché', 'noyau/x.phtml' => 'extension', 'tests/x.php' => 'dossier'] as $p => $q)
    ok(maj_chemin_refuse($p) !== null, "chemin refusé : $p ($q)");
egal(null, maj_chemin_refuse('secondaire/commun/pages/emploi_temps/saisie.php'), 'chemin normal accepté');
@unlink($zip);
