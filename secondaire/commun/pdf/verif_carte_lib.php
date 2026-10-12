<?php
// secondaire/commun/pdf/verif_carte_lib.php — signature et vérification
// d'authenticité des cartes scolaires du SECONDAIRE. Inclus par
// secondaire/commun/pdf/cartes.php (génération du QR) et par
// secondaire/commun/verif_carte_contenu.php (scan, via verif_carte.php).
//
// Hash inchangé (cartes déjà imprimées). Depuis le 12/10/2026 : URL absolue
// (adresse réseau joignable par un téléphone) et &ec=CODE en multi-école —
// avant, le QR menait à verif_carte.php sans code d'école, qui interrogeait
// le schéma du primaire (erreur fatale).

require_once __DIR__ . '/verif_lib.php';   // bulletin_verif_base_url() + verif_commun.php

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function carte_verif_hash(int $id_eleve, int $id_annee): string {
    $payload = $id_eleve . '|' . $id_annee;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 16);
}

function carte_verif_url(int $id_eleve, int $id_annee): string {
    $h = carte_verif_hash($id_eleve, $id_annee);
    return verif_ajout_ec(bulletin_verif_base_url() . '/verif_carte.php?e=' . $id_eleve . '&a=' . $id_annee . '&h=' . $h);
}

/** Élève si le hash de la carte (élève, année) est valide dans la base courante, sinon null. */
function carte_sec_verif_valider(int $id_eleve, int $id_annee, string $h): ?array {
    if ($id_eleve <= 0 || $id_annee <= 0 || $h === '' || !hash_equals(carte_verif_hash($id_eleve, $id_annee), $h)) return null;
    // Le hash ne dépend pas de l'école : exiger que l'élève soit bien
    // inscrit cette année-là dans CETTE base (évite de valider la carte
    // dans la mauvaise école lors de la recherche verif_trouver_ecole()).
    return db_one(
        "SELECT e.*, c.designation AS classe, a.libelle AS annee FROM eleve e
         JOIN inscription i ON i.id_eleve = e.id AND i.id_annee = ?
         JOIN classe c ON c.id = i.id_classe
         JOIN annee_scolaire a ON a.id = i.id_annee
         WHERE e.id = ? LIMIT 1", [$id_annee, $id_eleve]
    );
}
