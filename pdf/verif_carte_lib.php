<?php
// pdf/verif_carte_lib.php — signature et vérification d'authenticité des
// cartes scolaires. Même principe que pdf/verif_lib.php (bulletins) et
// pdf/verif_recu_lib.php (reçus), gardé dans un fichier séparé pour ne
// jamais risquer de désynchroniser les différents usages.
// Inclus par pdf/cartes.php (accès public via jeton) et verif_carte.php
// (vérification lors du scan) — pas de QR imprimé sur la carte physique
// pour l'instant (décision explicite de l'utilisateur, 15/08/2026), mais le
// mécanisme reste correct et utilisable si un lien de ce type est généré.

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}
require_once __DIR__ . '/verif_commun.php';

// $val_annee (ex. "2025/2026"), PAS un identifiant numérique — ce fichier
// avait été porté depuis ABZ_MBE avec un `int $id_annee` qui ne correspond
// à rien dans le schéma jaynitaare (annee_scolaire n'a pas de clé entière,
// seule `val_annee` — texte — identifie une année). Corrigé le 15/08/2026.
function carte_verif_hash(int $id_eleve, string $val_annee, ?string $secret = null): string {
    $payload = 'carte|' . $id_eleve . '|' . $val_annee;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 16);
}

function carte_verif_url(int $id_eleve, string $val_annee): string {
    $h = carte_verif_hash($id_eleve, $val_annee);
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    // hote_verif_reseau() (fonctions.php) remplace "localhost" par l'adresse
    // réseau réelle du serveur — indispensable pour qu'un téléphone qui
    // scanne puisse effectivement joindre le serveur.
    return verif_ajout_ec($scheme . '://' . hote_verif_reseau() . APP_URL . '/verif_carte.php?e=' . $id_eleve . '&a=' . urlencode($val_annee) . '&h=' . $h);
}

/**
 * Recharge toujours l'élève depuis la BD (jamais fait confiance à
 * l'appelant) — même défense que recu_verif_valider()/bulletin_verif_valider().
 */
function carte_verif_valider(int $id_eleve, string $val_annee, string $h_recu): ?array {
    if (!$id_eleve || $val_annee === '' || $h_recu === '') return null;
    $eleve = db_one("SELECT * FROM eleve WHERE id_eleve=?", [$id_eleve]);
    if (!$eleve) return null;
    if (hash_equals(carte_verif_hash($id_eleve, $val_annee), $h_recu)) return $eleve;
    if (hash_equals(carte_verif_hash($id_eleve, $val_annee, BULLETIN_VERIF_SECRET), $h_recu)) return $eleve;
    return null;
}
