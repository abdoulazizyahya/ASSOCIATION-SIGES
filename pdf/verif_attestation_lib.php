<?php
// pdf/verif_attestation_lib.php — signature et vérification d'authenticité
// des documents administratifs enseignants : attestation de présence
// effective, certificat de prise de service, certificat de reprise de
// service. Ces 3 types de document partagent ce fichier (même domaine
// "enseignant", pas de photo) mais restent des documents distincts : le
// hash inclut $type pour qu'un QR d'attestation ne valide jamais un
// certificat de prise/reprise de service, et inversement.
// Inclus par pages/enseignants/pdf_attestation.php et pdf_prise_service.php
// (génération du QR), et verif_attestation.php (vérification lors du scan).

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}
require_once __DIR__ . '/verif_commun.php';

function attestation_verif_hash(string $type, string $matricule, ?string $secret = null): string {
    $payload = 'attestation|' . $type . '|' . $matricule;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

/** Vérifie un hash reçu en acceptant le hash scopé école OU le hash legacy. */
function attestation_verif_hash_ok(string $type, string $matricule, string $recu): bool {
    return hash_equals(attestation_verif_hash($type, $matricule), $recu)
        || hash_equals(attestation_verif_hash($type, $matricule, BULLETIN_VERIF_SECRET), $recu);
}

function attestation_verif_base_url(): string {
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

function attestation_verif_url(string $type, string $matricule): string {
    $h = attestation_verif_hash($type, $matricule);
    return verif_ajout_ec(attestation_verif_base_url() . '/verif_attestation.php?t=' . urlencode($type) . '&m=' . urlencode($matricule) . '&h=' . $h);
}
