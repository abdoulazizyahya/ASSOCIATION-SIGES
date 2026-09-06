<?php
// =====================================================================
//  bd/assoc/generer_cles_verif_ecoles.php
//  Génère une paire de clés ECDSA P-256 PAR ÉCOLE pour la signature
//  offline des QR de bulletins, et stocke la clé PRIVÉE dans l'annuaire
//  (jaynitaare_assoc.etablissement.verif_cle_privee_pem).
//
//  Sans clé par école, toutes les écoles retombent sur la clé globale de
//  config.php (BULLETIN_VERIF_PRIVATE_KEY_PEM) → un bulletin d'une école
//  pourrait être présenté comme celui d'une autre en mode hors ligne.
//
//  Après exécution : coller le bloc « CLES_PUBLIQUES » affiché dans
//  verif_bulletin_hors_ligne.html (map code_école -> {x, y}).
//
//  Idempotent : une école qui a déjà une clé est ignorée (--force pour
//  la régénérer — invalide alors les QR offline déjà imprimés par cette
//  école).
//
//  Usage :  php bd/assoc/generer_cles_verif_ecoles.php [--force] [--only=CODE]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
if (!annuaire_dispo()) { die("Annuaire absent.\n"); }

$force = in_array('--force', $argv ?? [], true);
$only  = null;
foreach ($argv ?? [] as $a) { if (str_starts_with($a, '--only=')) $only = strtoupper(substr($a, 7)); }

// openssl.cnf : nécessaire sous Windows/WAMP (même contrainte que
// bd/generer_cles_verif_offline.php). Adapter le chemin si besoin.
$cnf_candidates = [
    getenv('OPENSSL_CONF') ?: null,
    'C:/wamp64/bin/php/php8.3.14/extras/ssl/openssl.cnf',
    '/etc/ssl/openssl.cnf',
];
$cnf = null;
foreach ($cnf_candidates as $c) { if ($c && is_file($c)) { $cnf = $c; break; } }
$opts = $cnf ? ['config' => $cnf] : [];

function b64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

$pub_map = [];
$rows = assoc_all("SELECT id, code, nom, verif_cle_privee_pem FROM etablissement WHERE actif=1 ORDER BY id");
foreach ($rows as $e) {
    if ($only && strtoupper($e['code']) !== $only) continue;
    $a_deja = trim((string) $e['verif_cle_privee_pem']) !== '';

    if ($a_deja && !$force) {
        // Ré-extrait la clé publique pour la reporter dans la map.
        $k = openssl_pkey_get_private($e['verif_cle_privee_pem']);
        if ($k) {
            $d = openssl_pkey_get_details($k);
            $pub_map[$e['code']] = ['x' => b64url($d['ec']['x']), 'y' => b64url($d['ec']['y'])];
        }
        echo str_pad($e['code'], 8) . "{$e['nom']}  — clé déjà présente (inchangée)\n";
        continue;
    }

    $res = openssl_pkey_new($opts + [
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$res || !openssl_pkey_export($res, $pem, null, $opts)) {
        echo str_pad($e['code'], 8) . "ÉCHEC openssl : " . openssl_error_string() . "\n";
        continue;
    }
    $d = openssl_pkey_get_details($res);
    $pub_map[$e['code']] = ['x' => b64url($d['ec']['x']), 'y' => b64url($d['ec']['y'])];

    assoc_exec("UPDATE etablissement SET verif_cle_privee_pem = ? WHERE id = ?", [$pem, $e['id']]);
    echo str_pad($e['code'], 8) . "{$e['nom']}  — nouvelle clé générée et enregistrée\n";
}

echo "\n" . str_repeat('=', 70) . "\n";
echo "À COLLER dans verif_bulletin_hors_ligne.html (remplace le bloc CLES_PUBLIQUES) :\n";
echo str_repeat('=', 70) . "\n\n";
echo "const CLES_PUBLIQUES = {\n";
foreach ($pub_map as $code => $xy) {
    echo "  " . json_encode((string) $code) . ": { x: '" . $xy['x'] . "', y: '" . $xy['y'] . "' },\n";
}
echo "  // '' = clé GLOBALE de config.php (repli pour les QR d'avant la clé par école) — NE PAS retirer :\n";
echo "  '': { x: 'hPC5CrHa4YngnX8t-CbNGcUMATIGJ1DsUNb7u7k_04c', y: 'n9UQ58tKtuzwvHyY9HCXaUghMwKU84qbg0R9s7_SCeQ' }\n";
echo "};\n";
