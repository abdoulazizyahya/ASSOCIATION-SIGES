<?php
// secondaire/commun/pdf/verif_releve_vierge_lib.php — signature et vérification d'authenticité
// du relevé de notes vierge (secondaire/technique/pages/notes/pdf_releve_vierge.php). Même
// principe que secondaire/commun/pdf/verif_fiche_presence_lib.php (voir ce fichier pour le
// détail) : hash HMAC (type + classe + année), QR imprimé sur le relevé,
// page publique secondaire/commun/verif/document_classe.php pour la vérification.

// SIGES (12/10/2026) : hash propre à l'école (verif_secret()), URL absolue
// + &ec=CODE ; vérification publique : secondaire/commun/verif/document_classe.php.
require_once __DIR__ . '/verif_lib.php';   // bulletin_verif_base_url(), verif_secret(), verif_ajout_ec()

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function releve_vierge_verif_hash(int $id_classe, int $id_annee): string {
    $payload = 'releve_vierge|' . $id_classe . '|' . $id_annee;
    return substr(hash_hmac('sha256', $payload, verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

function releve_vierge_verif_base_url(): string {
    return bulletin_verif_base_url();
}

function releve_vierge_verif_url(int $id_classe, int $id_annee): string {
    $h = releve_vierge_verif_hash($id_classe, $id_annee);
    return verif_ajout_ec(releve_vierge_verif_base_url() . '/secondaire/commun/verif/document_classe.php?t=releve&c=' . $id_classe . '&a=' . $id_annee . '&h=' . $h);
}
