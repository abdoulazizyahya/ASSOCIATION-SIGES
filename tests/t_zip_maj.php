<?php
// Zip de mise à jour : construit avec son manifeste, accepté tel quel, refusé
// dès qu'un fichier est altéré ou ajouté, ou qu'un chemin est dangereux.
// Installation depuis le portail (16/11/2026) : signature exigée, sauvegarde,
// fichiers retirés, puis annulation qui remet tout en l'état.
require __DIR__ . '/_amorce.php';
require_once 'bd/lib/mise_a_jour.php';

$zip = sys_get_temp_dir() . '/siges_test_maj.zip';
$depart = trim((string) shell_exec('git rev-list -n1 HEAD -- noyau')) . '~1';   // dernier commit qui touche l'application
exec(escapeshellarg(PHP_BINARY) . ' deploy/outils/construire_zip.php ' . escapeshellarg($depart) . ' ' . escapeshellarg(str_replace('\\', '/', $zip)) . ' 2>&1', $sortie, $rc);
egal(0, $rc, 'construire_zip.php sans erreur');
$an = maj_analyser($zip);
ok($an['ok'] && $an['verifie'], 'zip construit : accepté, empreintes vérifiées (' . count($an['fichiers']) . ' fichiers)');
$cle_privee = 'deploy/cles/maj_privee.pem';
if (is_file($cle_privee)) ok($an['signe'], 'zip construit : signature du propriétaire valide');

$z = new ZipArchive(); $z->open($zip);
$premier = array_key_first($an['fichiers']);
$z->addFromString($premier, "<?php // altéré\n");
$z->addFromString('noyau/ajout.php', "<?php\n");
$z->close();
$an = maj_analyser($zip);
ok(!$an['ok'] && preg_grep('/altéré/', $an['erreurs']) && preg_grep('/absent du manifeste/', $an['erreurs']), 'fichier altéré et fichier ajouté : zip refusé');

foreach (['../index.php' => 'remontée', 'config/config.local.php' => 'config serveur', '.htaccess' => 'fichier caché', 'noyau/x.phtml' => 'extension',
          'tests/x.php' => 'dossier', 'bd/lib/cle_maj_publique.pem' => 'clé publique'] as $p => $q)
    ok(maj_chemin_refuse($p) !== null, "chemin refusé : $p ($q)");
egal(null, maj_chemin_refuse('secondaire/commun/pages/emploi_temps/saisie.php'), 'chemin normal accepté');
@unlink($zip);

// ── Installation / annulation sur de vrais fichiers (docs/zz_test_maj_*) ──
if (!is_file($cle_privee) || !is_file(maj_cle_publique_chemin())) passer('clé de signature absente (php deploy/outils/generer_cle_maj.php)');
$f_nouveau = 'docs/zz_test_maj_nouveau.txt'; $f_modif = 'docs/zz_test_maj_modif.txt'; $f_retire = 'docs/zz_test_maj_retire.txt';
file_put_contents($f_modif, "avant\n"); file_put_contents($f_retire, "à retirer\n"); @unlink($f_nouveau);
$faire_zip = function (bool $signer) use ($zip, $f_nouveau, $f_modif, $f_retire, $cle_privee) {
    $contenus = [$f_nouveau => "nouveau\n", $f_modif => "après\n"];
    $man = json_encode(['commit' => 'test', 'depuis' => 'test', 'fichiers' => array_map(fn($c) => hash('sha256', $c), $contenus), 'supprimes' => [$f_retire]]);
    $z = new ZipArchive(); $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($contenus as $p => $c) $z->addFromString($p, $c);
    $z->addFromString(MAJ_MANIFESTE, $man);
    if ($signer) $z->addFromString(MAJ_SIGNATURE, maj_signer($man, file_get_contents($cle_privee)));
    $z->close();
};
$faire_zip(false);
$r = maj_installer($zip, 'test');
ok(!$r['ok'] && str_contains($r['message'], 'signature') && file_get_contents($f_modif) === "avant\n", 'zip non signé : installation refusée, rien modifié');

// Signature d'une autre clé : refusée.
$autre = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf') ? ['config' => $cnf] : []));
if ($autre) {
    openssl_pkey_export($autre, $pem_autre, null, is_file($cnf) ? ['config' => $cnf] : []);
    ok(!maj_verifier_signature('abc', maj_signer('abc', $pem_autre)), "signature d'une autre clé : refusée");
}

$faire_zip(true);
$r = maj_installer($zip, 'test');
ok($r['ok'] && $r['ecrits'] === 2 && $r['supprimes'] === 1, 'zip signé : installé (' . $r['message'] . ')');
ok(file_get_contents($f_nouveau) === "nouveau\n" && file_get_contents($f_modif) === "après\n" && !is_file($f_retire), 'fichiers écrits, fichier obsolète retiré');
$h = maj_historique();
egal($r['sauvegarde'], $h[0]['nom'] ?? null, 'mise à jour visible dans l\'historique');

$ra = maj_annuler((string) $r['sauvegarde']);
ok($ra['ok'], 'annulation : ' . $ra['message']);
ok(!is_file($f_nouveau) && file_get_contents($f_modif) === "avant\n" && file_get_contents($f_retire) === "à retirer\n", 'annulation : fichiers d\'avant remis, nouveau retiré');
ok(!maj_annuler((string) $r['sauvegarde'])['ok'], 'annulation : pas deux fois');

@unlink($f_modif); @unlink($f_retire); @unlink($f_nouveau); @unlink($zip);
@unlink(maj_dossier() . '/annulee_' . substr((string) $r['sauvegarde'], strlen('avant_')));
