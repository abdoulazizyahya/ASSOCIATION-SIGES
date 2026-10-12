<?php
// secondaire/commun/pdf/verif_paiement_lib.php — signature et vérification
// d'authenticité des reçus de paiement du secondaire (module « Paiement
// public » : paiement_frais). Inclus par secondaire/commun/pages/paiements/
// recu.php, recu_fiche.php, recap_paiement.php (génération du QR) et par
// verif_paiement.php à la racine (vérification lors du scan).
//
// Multi-établissement (12/10/2026) : les nouveaux QR portent &ec=CODE et un
// hash propre à l'école (verif_secret()). Les reçus imprimés AVANT — hash
// « legacy », sans code d'école — restent reconnus : paiement_verif_valider()
// accepte les deux, et verif_paiement.php retrouve leur école
// (verif_trouver_ecole()).

require_once __DIR__ . '/verif_lib.php';   // bulletin_verif_base_url() (URL absolue, adresse réseau) + verif_commun.php

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function paiement_verif_hash(int $id_paiement, string $numero_recu, ?string $secret = null): string {
    $payload = $id_paiement . '|' . $numero_recu;
    return substr(hash_hmac('sha256', $payload, $secret ?? verif_secret(BULLETIN_VERIF_SECRET)), 0, 20);
}

function paiement_verif_url(int $id_paiement, string $numero_recu): string {
    $h = paiement_verif_hash($id_paiement, $numero_recu);
    return verif_ajout_ec(bulletin_verif_base_url() . '/verif_paiement.php?p=' . $id_paiement . '&h=' . $h);
}

/** Paiement (avec l'élève) si le hash correspond — nouveau ou ancien format —, sinon null. */
function paiement_verif_valider(int $id_paiement, string $h): ?array {
    if ($id_paiement <= 0 || $h === '') return null;
    $p = db_one(
        "SELECT p.*, e.nom, e.prenom, e.matricule, e.niu FROM paiement_frais p
         JOIN eleve e ON e.id = p.id_eleve WHERE p.id = ?", [$id_paiement]
    );
    if (!$p) return null;
    foreach ([null, BULLETIN_VERIF_SECRET] as $secret) {
        if (hash_equals(paiement_verif_hash($id_paiement, (string) $p['numero_recu'], $secret), $h)) return $p;
    }
    return null;
}
