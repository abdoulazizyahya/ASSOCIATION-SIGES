<?php
// pdf/verif_carte_lib.php — signature et vérification d'authenticité des
// cartes scolaires. Même principe que pdf/verif_lib.php (bulletins) et
// pdf/verif_paiement_lib.php (reçus), gardé dans un fichier séparé pour ne
// jamais risquer de désynchroniser les différents usages.
// Inclus par pdf/cartes.php (génération du QR) et verif_carte.php (scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function carte_verif_hash(int $id_eleve, int $id_annee): string {
    $payload = $id_eleve . '|' . $id_annee;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 16);
}

function carte_verif_url(int $id_eleve, int $id_annee): string {
    $h = carte_verif_hash($id_eleve, $id_annee);
    return APP_URL . '/verif_carte.php?e=' . $id_eleve . '&a=' . $id_annee . '&h=' . $h;
}
