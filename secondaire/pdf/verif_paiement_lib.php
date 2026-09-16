<?php
// pdf/verif_paiement_lib.php — signature et vérification d'authenticité des
// reçus de paiement. Même principe que pdf/verif_lib.php (bulletins), mais
// gardé dans un fichier séparé pour ne jamais risquer de désynchroniser les
// deux usages (chacun a son propre payload/format d'URL).
// Inclus par secondaire/pages/paiements/recu.php (génération du QR) et verif_paiement.php
// (vérification lors du scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function paiement_verif_hash(int $id_paiement, string $numero_recu): string {
    $payload = $id_paiement . '|' . $numero_recu;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 20);
}

function paiement_verif_url(int $id_paiement, string $numero_recu): string {
    $h = paiement_verif_hash($id_paiement, $numero_recu);
    return APP_URL . '/verif_paiement.php?p=' . $id_paiement . '&h=' . $h;
}
