<?php
// deploy/outils/generer_cle_maj.php — paire de clés des zips de mise à jour (16/11/2026).
//
//   php deploy/outils/generer_cle_maj.php            (refuse d'écraser une clé existante)
//   php deploy/outils/generer_cle_maj.php --remplacer
//
// Clé PRIVÉE : deploy/cles/maj_privee.pem — reste sur le poste du propriétaire
//   (jamais en dépôt, jamais dans un zip, jamais sur le serveur). La perdre
//   oblige à générer une nouvelle paire et à redéposer la clé publique par FTP.
// Clé PUBLIQUE : bd/lib/cle_maj_publique.pem — versionnée, déposée UNE fois sur
//   le serveur par FTP. Elle ne peut pas être remplacée par un zip (extension
//   .pem refusée), donc un zip ne peut pas changer la clé qui le vérifie.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
chdir(__DIR__ . '/../..');

$priv = 'deploy/cles/maj_privee.pem';
$pub  = 'bd/lib/cle_maj_publique.pem';
if (is_file($priv) && !in_array('--remplacer', $argv, true)) exit("La clé $priv existe déjà (ajouter --remplacer pour en créer une nouvelle).\n");

$opts = ['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$k = @openssl_pkey_new($opts);
if (!$k) {   // WAMP : openssl.cnf absent de l'emplacement par défaut
    $cnf = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
    if (is_file($cnf)) $k = openssl_pkey_new($opts + ['config' => $cnf]);
    if (!$k) exit('Génération impossible : ' . openssl_error_string() . "\n");
    openssl_pkey_export($k, $pem_priv, null, ['config' => $cnf]);
} else {
    openssl_pkey_export($k, $pem_priv);
}
@mkdir(dirname($priv), 0700, true);
file_put_contents($priv, $pem_priv);
file_put_contents($pub, openssl_pkey_get_details($k)['key']);
echo "Clé privée : $priv (à garder précieusement, en faire une copie hors du poste)\n";
echo "Clé publique : $pub (à déposer une fois sur le serveur, au même chemin)\n";
