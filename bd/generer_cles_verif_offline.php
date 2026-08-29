<?php
// bd/generer_cles_verif_offline.php — génère une paire de clés ECDSA P-256
// pour la signature offline des QR de bulletins (à exécuter UNE SEULE FOIS,
// php bd/generer_cles_verif_offline.php). Affiche la clé privée (PEM, à
// coller dans config.php) et la clé publique (JWK, à coller dans
// verif_bulletin_hors_ligne.html) — ne rien enregistrer d'autre sur disque.
header('Content-Type: text/plain; charset=utf-8');

$res = openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'config' => 'C:/wamp64/bin/php/php8.3.14/extras/ssl/openssl.cnf',
]);
if (!$res) {
    die("Échec openssl_pkey_new() : " . openssl_error_string() . "\n");
}

$config_opts = ['config' => 'C:/wamp64/bin/php/php8.3.14/extras/ssl/openssl.cnf'];
if (!openssl_pkey_export($res, $prive_pem, null, $config_opts)) {
    die("Échec openssl_pkey_export() : " . openssl_error_string() . "\n");
}
$details = openssl_pkey_get_details($res);
$x = $details['ec']['x'];
$y = $details['ec']['y'];

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

echo "=== Clé PRIVÉE (PEM) — à coller dans config.php ===\n";
echo "define('BULLETIN_VERIF_PRIVATE_KEY_PEM', <<<'PEM'\n";
echo $prive_pem;
echo "PEM\n);\n\n";

echo "=== Clé PUBLIQUE (JWK x/y, base64url) — à coller dans verif_bulletin_hors_ligne.html ===\n";
echo "x: " . b64url($x) . "\n";
echo "y: " . b64url($y) . "\n";
