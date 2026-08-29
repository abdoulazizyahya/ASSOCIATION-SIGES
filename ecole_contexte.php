<?php
// ── Contexte « établissement courant » (multi-établissement) ─────────
//  Résout, à chaque requête, DANS QUELLE base école on travaille :
//    1. sous-domaine        ecole1.assoc.cm            (production)
//    2. paramètre ?ec=CODE  (pages publiques verif_*, visiteur anonyme)
//    3. session             (login école OU visite d'un membre asso)
//    4. repli               école n°1 (compat mono-école / login direct)
//
//  Inclus par connexion.php APRÈS connexion_assoc.php. Sans effet si
//  l'annuaire est absent (installation mono-école historique).
//
//  Expose :
//    resoudre_etablissement() : ?array   ligne annuaire, ou null (contexte asso)
//    ecole_courante()         : ?array   idem, après résolution (global $ETAB_COURANT)
//    est_contexte_association(): bool     page sous /association/
//    est_visite_association()  : bool     un membre a « ouvert » une école
//    est_lecture_seule()       : bool     écritures interdites (visite asso)

require_once __DIR__ . '/config.php';

// Démarre la session avec EXACTEMENT les mêmes paramètres que
// fonctions.php::session_init() (nom + path dédiés à cette app). Doit
// tourner avant tout envoi de sortie ; fonctions.php::session_init()
// deviendra alors un no-op (il teste session_status()).
function ecole_session_demarrer(): void {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_name('JAYNITAARE_SESSID');
        session_set_cookie_params([
            'path'     => APP_URL . '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Page servie depuis le dossier de l'interface association ?
function est_contexte_association(): bool {
    $s = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
    return strpos($s, '/association/') !== false;
}

// Un membre de l'association a « ouvert » une école pour la consulter.
function est_visite_association(): bool {
    ecole_session_demarrer();
    return !empty($_SESSION['visite_asso']);
}

// En visite association, toute écriture est interdite (lecture seule).
function est_lecture_seule(): bool {
    return est_visite_association() && empty($_SESSION['visite_asso_ecriture']);
}

// Sous-domaine de la requête (« ecole1 » pour ecole1.assoc.cm), ou ''.
function _sous_domaine_requete(): string {
    $host = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return '';
    $parts = explode('.', $host);
    if (count($parts) < 2) return '';                       // « localhost »
    $sub = $parts[0];
    return in_array($sub, ['www', 'localhost', 'admin', 'assoc'], true) ? '' : $sub;
}

function resoudre_etablissement(): ?array {
    if (!annuaire_dispo()) return null;

    // Contexte association : on travaille dans la base annuaire, pas d'école.
    if (est_contexte_association()) return null;

    // 1. Sous-domaine (production)
    if ($sub = _sous_domaine_requete()) {
        $e = assoc_one("SELECT * FROM etablissement WHERE sous_domaine=? AND actif=1", [$sub]);
        if ($e) return $e;
    }

    // 2. ?ec=CODE — pages publiques de vérification (aucune écriture de session)
    if (!empty($_GET['ec'])) {
        $e = assoc_one("SELECT * FROM etablissement WHERE code=? AND actif=1", [(string) $_GET['ec']]);
        if ($e) return $e;
    }

    // 3. Session — login école ou visite d'un membre association
    ecole_session_demarrer();
    if (!empty($_SESSION['ecole']['id'])) {
        $e = assoc_one("SELECT * FROM etablissement WHERE id=? AND actif=1", [(int) $_SESSION['ecole']['id']]);
        if ($e) return $e;
    }

    // 4. Repli : école n°1 (installation mono-école, ou login direct avant
    //    choix d'école quand une seule école existe)
    return assoc_one("SELECT * FROM etablissement WHERE code='EC1' AND actif=1")
        ?? assoc_one("SELECT * FROM etablissement WHERE actif=1 ORDER BY id LIMIT 1");
}

/** Ligne annuaire de l'école courante (null en contexte association). */
function ecole_courante(): ?array {
    global $ETAB_COURANT;
    return $ETAB_COURANT ?: null;
}
