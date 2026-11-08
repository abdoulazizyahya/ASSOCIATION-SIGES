<?php
// secondaire/commun/pdf/verif_honneur_lib.php — signature et vérification
// d'authenticité des certificats « Tableau d'honneur » d'une école
// SECONDAIRE. Séparé de verif_lib.php (bulletins) : un QR de tableau
// d'honneur ne doit jamais valider un bulletin, même si les deux documents
// partagent le même élève/période.
// Inclus par secondaire/commun/pages/tableau_honneur/pdf_certificat.php
// (génération du QR) et secondaire/commun/verif_honneur_contenu.php
// (vérification lors du scan, via verif/honneur.php).
//
// 08/11/2026 : l'URL porte désormais &ec=CODE (verif_ajout_ec()) — sans lui,
// en multi-établissement, le scan affichait « Établissement non précisé ».
// La signature ne change pas : les certificats déjà imprimés restent valides
// (leur école est retrouvée par verif_trouver_ecole(), voir verif/honneur.php).

require_once __DIR__ . '/verif_lib.php';   // bulletin_verif_base_url() + verif_commun.php

if (!defined('BULLETIN_VERIF_SECRET')) {
    define('BULLETIN_VERIF_SECRET', 'CHANGE_ME_ABZ_MBE_INSECURE_DEFAULT_SECRET');
}

function honneur_verif_hash(int $id_eleve, string $vue, int $id_periode, string $matricule): string {
    $payload = 'honneur|' . $id_eleve . '|' . $vue . '|' . $id_periode . '|' . $matricule;
    return substr(hash_hmac('sha256', $payload, BULLETIN_VERIF_SECRET), 0, 20);
}

function honneur_verif_base_url(): string {
    return bulletin_verif_base_url();
}

/** URL du QR. $modele (présentation seulement, non signé) : rouvre le même modèle de certificat. */
function honneur_verif_url(int $id_eleve, string $vue, int $id_periode, string $matricule, int $modele = 0): string {
    $h = honneur_verif_hash($id_eleve, $vue, $id_periode, $matricule);
    return verif_ajout_ec(honneur_verif_base_url() . '/verif/honneur.php?e=' . $id_eleve . '&v=' . $vue . '&p=' . $id_periode
        . ($modele > 0 ? '&m=' . $modele : '') . '&h=' . $h);
}

/**
 * Élève si la signature du certificat est valide dans la base courante,
 * sinon null. $vue : 'trim' | 'seq' | 'annee' ; $id_periode : id du
 * trimestre, de la séquence ou de l'année. La signature porte l'identifiant
 * affiché de l'élève (NIU sinon matricule) au moment de l'impression.
 */
function honneur_sec_verif_valider(int $id_eleve, string $vue, int $id_periode, string $h): ?array {
    if ($id_eleve <= 0 || $id_periode <= 0 || $h === '' || !in_array($vue, ['trim', 'seq', 'annee'], true)) return null;
    $e = db_one("SELECT * FROM eleve WHERE id=?", [$id_eleve]);
    if (!$e) return null;
    $ids = array_unique(array_filter([id_affichage_eleve($e), (string) ($e['matricule'] ?? ''), (string) ($e['niu'] ?? '')], 'strlen'));
    foreach ($ids as $m) {
        if (hash_equals(honneur_verif_hash($id_eleve, $vue, $id_periode, $m), $h)) return $e;
    }
    return null;
}
