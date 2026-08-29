<?php
// pdf/verif_scolarite_lib.php — signature et vérification d'authenticité des
// certificats de scolarité. Fichier séparé des autres pdf/verif_*_lib.php
// (bulletins, cartes, tableau d'honneur, paiements) : un QR de certificat de
// scolarité ne doit jamais valider un autre type de document.
// Inclus par pdf/certificat_scolarite.php (génération du QR) et
// verif_scolarite.php (vérification lors du scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}
require_once __DIR__ . '/verif_commun.php';

function scolarite_verif_hash(int $id_eleve, string $matricule, ?string $secret = null): string {
    $payload = 'scolarite|' . $id_eleve . '|' . $matricule;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

/** Vérifie un hash reçu en acceptant le hash scopé école OU le hash legacy. */
function scolarite_verif_hash_ok(int $id_eleve, string $matricule, string $recu): bool {
    return hash_equals(scolarite_verif_hash($id_eleve, $matricule), $recu)
        || hash_equals(scolarite_verif_hash($id_eleve, $matricule, BULLETIN_VERIF_SECRET), $recu);
}

function scolarite_verif_base_url(): string {
    if (defined('BULLETIN_VERIF_BASE_URL') && BULLETIN_VERIF_BASE_URL !== '') {
        return rtrim(BULLETIN_VERIF_BASE_URL, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    // hote_verif_reseau() (fonctions.php) remplace "localhost" par l'adresse
    // réseau réelle du serveur — indispensable pour qu'un téléphone qui
    // scanne le QR puisse effectivement joindre le serveur.
    return $scheme . '://' . hote_verif_reseau() . APP_URL;
}

function scolarite_verif_url(int $id_eleve, string $matricule): string {
    $h = scolarite_verif_hash($id_eleve, $matricule);
    return verif_ajout_ec(scolarite_verif_base_url() . '/verif_scolarite.php?e=' . $id_eleve . '&h=' . $h);
}
