<?php
// pdf/verif_recu_lib.php — signature et vérification d'authenticité du reçu
// de paiement PAR ÉLÈVE (pages/finances/recu.php). Même pattern que
// pdf/verif_lib.php (bulletins) : hash HMAC signé, QR mis en cache disque,
// page publique verif_recu.php qui recharge toujours les données depuis la
// BD (jamais confiance en un paramètre d'URL). Secret séparé de
// BULLETIN_VERIF_SECRET (documents différents, jamais interchangeables).

if (!defined('RECU_VERIF_SECRET')) {
    // ⚠️ À FAIRE AVANT MISE EN PRODUCTION : définir une vraie valeur secrète
    // dans config.php, ex. define('RECU_VERIF_SECRET', bin2hex(random_bytes(32)));
    define('RECU_VERIF_SECRET', 'CHANGE_ME_RECU_INSECURE_DEFAULT_SECRET');
}
require_once __DIR__ . '/verif_commun.php';

/**
 * Signature d'un reçu (élève + année scolaire + numéro affiché) — le numéro
 * est inclus dans le payload pour que le hash change si jamais le format de
 * numérotation évolue plus tard (voir finances_numero_recu_eleve()).
 * $secret : forcé à la vérification pour tester le hash legacy (sans école).
 */
function recu_verif_hash(int $id_eleve, string $val_annee, string $numero, ?string $secret = null): string {
    $payload = $id_eleve . '|' . $val_annee . '|' . $numero;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(RECU_VERIF_SECRET)), 0, 20);
}

function recu_verif_base_url(): string {
    if (defined('RECU_VERIF_BASE_URL') && RECU_VERIF_BASE_URL !== '') {
        return rtrim(RECU_VERIF_BASE_URL, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    // hote_verif_reseau() (fonctions.php) remplace "localhost" par l'adresse
    // réseau réelle du serveur — indispensable pour qu'un téléphone qui
    // scanne le QR du reçu puisse effectivement joindre le serveur.
    return $scheme . '://' . hote_verif_reseau() . APP_URL;
}

function recu_verif_url(int $id_eleve, string $val_annee, string $numero): string {
    $h = recu_verif_hash($id_eleve, $val_annee, $numero);
    return verif_ajout_ec(recu_verif_base_url() . '/verif_recu.php?e=' . $id_eleve . '&a=' . urlencode($val_annee) . '&h=' . $h);
}

/**
 * Génère le PNG du QR de vérification du reçu dans assets/uploads/qr_recus/
 * (mis en cache, même principe que bulletin_qr_fichier_temp() — contenu
 * entièrement déterministe pour un (élève, année) donné, seule la clé
 * secrète le fait changer). Pas de photo incrustée (le modèle de référence
 * n'en a pas — QR simple, contrairement aux bulletins).
 */
function recu_qr_fichier_temp(int $id_eleve, string $val_annee, string $numero): ?string {
    require_once __DIR__ . '/qrcode.php';
    if (!$id_eleve) return null;

    // Le hash de recu_verif_base_url() intègre l'adresse réseau du serveur :
    // sans lui, un QR déjà en cache gardait l'ancienne IP après un changement
    // de réseau/SERVEUR_LAN_HOST (voir bulletin_qr_fichier_temp()).
    $cle_cache = hash('crc32b', verif_secret(RECU_VERIF_SECRET)) . '_' . $id_eleve
        . '_' . hash('crc32b', $val_annee . $numero)
        . '_' . hash('crc32b', recu_verif_base_url());
    $dossier_cache = __DIR__ . '/../assets/uploads/qr_recus';
    if (!is_dir($dossier_cache)) @mkdir($dossier_cache, 0755, true);
    $chemin_cache = $dossier_cache . '/' . $cle_cache . '.png';

    if (is_file($chemin_cache)) return $chemin_cache;

    $verif_url = recu_verif_url($id_eleve, $val_annee, $numero);
    $qr_gen = new QRCode($verif_url, ['s' => 'qr-h']);
    $qr_img = $qr_gen->render_image();
    imagepng($qr_img, $chemin_cache);
    imagedestroy($qr_img);
    return is_file($chemin_cache) ? $chemin_cache : null;
}

/**
 * Vérifie un jeton de reçu reçu (scan QR) — recharge toujours l'élève depuis
 * la BD, seul le hash HMAC recalculé fait foi (même défense qu'
 * bulletin_verif_valider()).
 */
function recu_verif_valider(int $id_eleve, string $val_annee, string $h_recu): ?array {
    if (!$id_eleve || $val_annee === '' || $h_recu === '') return null;
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
    if (!$eleve) return null;
    $numero = finances_numero_recu_eleve($id_eleve, $val_annee);
    // hash « scopé école » (nouveau) OU hash legacy (reçus imprimés avant le
    // multi-établissement — déjà routés vers la bonne base par ?ec=).
    if (hash_equals(recu_verif_hash($id_eleve, $val_annee, $numero), $h_recu)) return $eleve;
    if (hash_equals(recu_verif_hash($id_eleve, $val_annee, $numero, RECU_VERIF_SECRET), $h_recu)) return $eleve;
    return null;
}
