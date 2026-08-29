<?php
// pdf/verif_paiement_lib.php — signature et vérification d'authenticité des
// reçus de paiement. Même principe que pdf/verif_lib.php (bulletins), mais
// gardé dans un fichier séparé pour ne jamais risquer de désynchroniser les
// deux usages (chacun a son propre payload/format d'URL).
// Inclus par pages/paiements/recu.php (génération du QR) et verif_paiement.php
// (vérification lors du scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}
require_once __DIR__ . '/verif_commun.php';

function paiement_verif_hash(int $id_paiement, string $numero_recu, ?string $secret = null): string {
    $payload = $id_paiement . '|' . $numero_recu;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

/** Vérifie un hash reçu en acceptant le hash scopé école OU le hash legacy. */
function paiement_verif_hash_ok(int $id_paiement, string $numero_recu, string $recu): bool {
    return hash_equals(paiement_verif_hash($id_paiement, $numero_recu), $recu)
        || hash_equals(paiement_verif_hash($id_paiement, $numero_recu, BULLETIN_VERIF_SECRET), $recu);
}

function paiement_verif_url(int $id_paiement, string $numero_recu): string {
    $h = paiement_verif_hash($id_paiement, $numero_recu);
    // URL absolue (schéma+hôte), pas juste APP_URL (chemin relatif — inutilisable
    // dans un QR code) — hote_verif_reseau() (fonctions.php) remplace en plus
    // "localhost" par l'adresse réseau réelle du serveur, sinon un téléphone
    // qui scanne ne peut pas joindre le serveur.
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    return verif_ajout_ec($scheme . '://' . hote_verif_reseau() . APP_URL . '/verif_paiement.php?p=' . $id_paiement . '&h=' . $h);
}
