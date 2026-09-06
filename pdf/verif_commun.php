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

/**
 * URL publique de vérification propre à l'école courante, si elle est
 * renseignée dans l'annuaire (etablissement.verif_base_url) — utile quand
 * chaque école a son propre nom de domaine / sous-domaine en production.
 * '' sinon (les *_verif_base_url() déduisent alors l'hôte de la requête).
 */
function verif_base_url_ecole(): string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    return $e && !empty($e['verif_base_url']) ? rtrim($e['verif_base_url'], '/') : '';
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

/**
 * Garde des pages PUBLIQUES de vérification (verif_*.php) en multi-école :
 * si l'URL scannée n'a pas résolu d'établissement (pas de &ec=CODE, pas de
 * sous-domaine), on ne sait pas dans quelle base chercher le document —
 * afficher une page neutre plutôt qu'une erreur SQL. Sans effet en
 * installation mono-école ou quand une école est résolue (cas normal : le
 * QR imprimé porte &ec= — voir verif_ajout_ec()).
 */
function verif_exiger_ecole_publique(): void {
    if (!verif_multi_ecoles()) return;
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    if ($e) return;
    http_response_code(400);
    $url = defined('APP_URL') ? APP_URL : '';
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Lien de vérification incomplet</title>'
       . '<link rel="stylesheet" href="' . $url . '/assets/vendor/bootstrap/css/bootstrap.min.css"></head>'
       . '<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f1a3a">'
       . '<div style="background:#fff;border-radius:16px;padding:2rem;max-width:440px;text-align:center;font-family:system-ui,sans-serif">'
       . '<div style="font-size:2.6rem">🔗</div>'
       . '<h5 class="mt-2">Établissement non précisé</h5>'
       . '<p class="text-muted" style="font-size:.9rem">Ce lien de vérification ne précise pas l\'établissement '
       . 'émetteur du document. Rescannez le QR code d\'origine, ou demandez le document à jour à l\'établissement.</p>'
       . '</div></body></html>';
    exit;
}
