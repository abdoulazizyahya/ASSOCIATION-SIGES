<?php
// pdf/verif_lib.php — signature et vérification d'authenticité des bulletins.
// Inclus à la fois par bulletins/pdf.php (génération du QR) et verif_bulletin.php
// (vérification lors du scan). Garder ces deux usages synchronisés.

if (!defined('BULLETIN_VERIF_SECRET')) {
    // ⚠️ IMPORTANT — À FAIRE AVANT MISE EN PRODUCTION :
    // Définir une vraie valeur secrète et aléatoire dans config.php, par ex. :
    //   define('BULLETIN_VERIF_SECRET', 'colle_ici_une_chaine_aleatoire_longue');
    // (générable une fois avec : php -r "echo bin2hex(random_bytes(32));")
    // Tant que ce fallback est utilisé, n'importe qui lisant ce fichier peut
    // forger un QR "authentique" : la vérification n'est alors pas fiable.
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

/**
 * Calcule la signature d'un bulletin (élève + période + matricule).
 * $vue = 'seq' ou 'trim' ; $id_periode = id_seq ou id_trim selon le cas.
 */
function bulletin_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule): string {
    $payload = $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 20);
}

/**
 * Base absolue (schéma + hôte + chemin de l'appli) pour les URL encodées dans
 * un QR code : contrairement à un lien HTML classique, un lecteur de QR code
 * externe (téléphone d'un parent, etc.) n'a pas de page de référence pour
 * résoudre une URL relative comme APP_URL ('/ABZ_MBE') — il lui faut l'adresse
 * complète. Définir BULLETIN_VERIF_BASE_URL dans config.php si le nom
 * d'hôte public diffère de celui de la requête courante (ex. reverse proxy) ;
 * sinon déduit automatiquement de la requête en cours.
 */
function bulletin_verif_base_url(): string {
    if (defined('BULLETIN_VERIF_BASE_URL') && BULLETIN_VERIF_BASE_URL !== '') {
        return rtrim(BULLETIN_VERIF_BASE_URL, '/');
    }
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . APP_URL;
}

/**
 * Construit l'URL de vérification à encoder dans le QR code.
 */
function bulletin_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule): string {
    $h = bulletin_verif_hash($id_eleve, $vue, $id_periode, $matricule);
    return bulletin_verif_base_url() . '/verif_bulletin.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode . '&h=' . $h;
}
