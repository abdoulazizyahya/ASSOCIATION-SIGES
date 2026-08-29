<?php
// pdf/verif_commun.php — helpers multi-établissement partagés par les
// 7 familles de documents vérifiables (bulletin, reçu, honneur, scolarité,
// paiement, attestation, carte).
//
//  But : lier chaque QR à SON école quand l'annuaire compte plus d'une
//  école active, sans casser les QR déjà imprimés — le hash « legacy »
//  (sans école) reste accepté à la vérification, et en installation
//  mono-école le format des QR ne change pas du tout.

/** Plus d'une école active dans l'annuaire ? (mémoïsé) */
function verif_multi_ecoles(): bool {
    static $m = null;
    if ($m === null) {
        $m = function_exists('annuaire_dispo') && annuaire_dispo()
          && (int) assoc_val("SELECT COUNT(*) FROM etablissement WHERE actif=1") > 1;
    }
    return $m;
}

/** Code de l'école courante à intégrer aux QR/hash — '' si mono-école. */
function verif_ec(): string {
    if (!verif_multi_ecoles()) return '';
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    return (string) ($e['code'] ?? '');
}

/** Secret HMAC « scopé école » pour la génération d'un nouveau QR. */
function verif_secret(string $secret): string {
    $ec = verif_ec();
    return $ec === '' ? $secret : $secret . '|ETAB:' . $ec;
}

/** Ajoute &ec=CODE à une URL de vérification (multi-école uniquement). */
function verif_ajout_ec(string $url): string {
    $ec = verif_ec();
    if ($ec === '') return $url;
    return $url . (strpos($url, '?') !== false ? '&' : '?') . 'ec=' . rawurlencode($ec);
}

/**
 * Clé privée ECDSA P-256 de signature offline : celle de l'école si elle est
 * définie dans l'annuaire (etablissement.verif_cle_privee_pem), sinon la clé
 * globale de config.php (BULLETIN_VERIF_PRIVATE_KEY_PEM).
 */
function verif_cle_privee_pem(): ?string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    if ($e && !empty($e['verif_cle_privee_pem'])) return $e['verif_cle_privee_pem'];
    return defined('BULLETIN_VERIF_PRIVATE_KEY_PEM') ? BULLETIN_VERIF_PRIVATE_KEY_PEM : null;
}
