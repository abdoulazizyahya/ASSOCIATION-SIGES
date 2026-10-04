<?php
// pdf/verif_honneur_lib.php — signature et vérification d'authenticité des
// certificats "Tableau d'honneur". Fichier séparé de pdf/verif_lib.php
// (bulletins) : un QR de tableau d'honneur ne doit jamais valider un
// bulletin, même si les deux documents partagent le même élève/période.
// Inclus par secondaire/pages/tableau_honneur/pdf_certificat.php (génération du QR) et
// verif_honneur.php (vérification lors du scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function honneur_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule): string {
    $payload = 'honneur|' . $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 20);
}

// Copie du helper de pdf/verif_lib.php (même logique) : voir là-bas pour le
// détail du raisonnement (URL absolue nécessaire pour un lecteur de QR externe).
function honneur_verif_base_url(): string {
    if (defined('BULLETIN_VERIF_BASE_URL') && BULLETIN_VERIF_BASE_URL !== '') {
        return rtrim(BULLETIN_VERIF_BASE_URL, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = function_exists('hote_verif_reseau') ? hote_verif_reseau() : ($_SERVER['HTTP_HOST'] ?? 'localhost');   // adresse réseau, pas « localhost » (03/10/2026)
    return $scheme . '://' . $host . APP_URL;
}

function honneur_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule): string {
    $h = honneur_verif_hash($id_eleve, $vue, $id_periode, $matricule);
    return honneur_verif_base_url() . '/verif_honneur.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode . '&h=' . $h;
}
