<?php
// secondaire/commun/pdf/verif_fiche_presence_lib.php — signature et vérification d'authenticité
// de la fiche de présence vierge (secondaire/commun/pages/discipline/pdf_fiche_presence.php).
// Même principe que secondaire/commun/pdf/verif_lib.php (bulletins) / secondaire/commun/pdf/verif_attestation_lib.php :
// le hash HMAC couvre le type de document + la classe + l'année scolaire ; un
// QR imprimé sur la fiche renvoie vers secondaire/commun/verif/document_classe.php (page
// publique, sans connexion) qui affiche "authentique" (avec bouton "Ouvrir")
// ou non. Inclus à la fois par pdf_fiche_presence.php (génération du QR) et
// secondaire/commun/verif/document_classe.php (vérification lors du scan) — garder synchronisés.

// SIGES (12/10/2026) : hash propre à l'école (verif_secret()), URL absolue
// + &ec=CODE ; vérification publique : secondaire/commun/verif/document_classe.php.
require_once __DIR__ . '/verif_lib.php';   // bulletin_verif_base_url(), verif_secret(), verif_ajout_ec()

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function fiche_presence_verif_hash(int $id_classe, int $id_annee): string {
    $payload = 'fiche_presence|' . $id_classe . '|' . $id_annee;
    return substr(hash_hmac('sha256', $payload, verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

function fiche_presence_verif_base_url(): string {
    return bulletin_verif_base_url();
}

function fiche_presence_verif_url(int $id_classe, int $id_annee): string {
    $h = fiche_presence_verif_hash($id_classe, $id_annee);
    return verif_ajout_ec(fiche_presence_verif_base_url() . '/secondaire/commun/verif/document_classe.php?t=fiche&c=' . $id_classe . '&a=' . $id_annee . '&h=' . $h);
}
