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

// ── Bascule de la base « école courante » ───────────────────────────
//  Pose $_SESSION['ecole'], met à jour $ETAB_COURANT et RE-sélectionne la
//  base sur $link (connexion.php a déjà tourné à l'inclusion de la page —
//  cette fonction est appelée plus tard, à la soumission d'un formulaire
//  de choix d'école ou à l'entrée d'un membre dans une école).
function basculer_base_ecole(array $etab): void {
    global $link, $ETAB_COURANT;
    ecole_session_demarrer();
    $_SESSION['ecole'] = [
        'id'      => (int) $etab['id'],
        'code'    => $etab['code'],
        'db_name' => $etab['db_name'],
        'nom'     => $etab['nom'],
    ];
    $ETAB_COURANT = $etab;
    if ($link instanceof mysqli) {
        mysqli_select_db($link, $etab['db_name']);
    }
}

// ── Membre de l'association connecté au portail ─────────────────────
function membre_connecte(): array {
    ecole_session_demarrer();
    return $_SESSION['membre'] ?? [];
}

function est_membre_association(): bool {
    ecole_session_demarrer();
    return !empty($_SESSION['membre']['id']);
}

/** Garde des pages sous association/ (hors login). */
function exiger_membre_association(): void {
    if (!annuaire_dispo()) {
        die('<div style="font-family:sans-serif;padding:2rem;color:red">
             Annuaire association non installé (bd/assoc/installer.php).</div>');
    }
    if (!est_membre_association()) {
        header('Location: ' . APP_URL . '/association/login.php');
        exit;
    }
}

/** Journalise une action d'un membre (traçabilité des visites/écritures). */
function journaliser_action(string $action, ?int $id_etab = null, ?string $cible = null): void {
    if (!annuaire_dispo()) return;
    $m = membre_connecte();
    assoc_exec(
        "INSERT INTO journal_action (id_membre, id_etablissement, action, cible, ip)
         VALUES (?, ?, ?, ?, ?)",
        [$m['id'] ?? null, $id_etab, $action, $cible, $_SERVER['REMOTE_ADDR'] ?? null]
    );
}

// ── Registre NIU central (jaynitaare_assoc.eleve_niu) ───────────────
//  Appelés depuis les pages « école » (pages/eleves/save.php) pour tenir
//  à jour l'identité et l'école courante d'un élève au niveau association.
//  Sans annuaire : no-op (mode mono-école).
//
//  $ident : ['nom','prenom','date_naiss','sexe','lieu_naiss']

function niu_enregistrer_inscription(string $niu, array $ident): void {
    if (!annuaire_dispo() || trim($niu) === '') return;
    $ec = ecole_courante();
    $id_e = $ec['id'] ?? null;
    $p = [
        $ident['nom'] ?? null, $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
        $ident['sexe'] ?? null, $ident['lieu_naiss'] ?? null,
    ];
    if (assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE niu=?", [$niu])) {
        assoc_exec(
            "UPDATE eleve_niu SET nom=?, prenom=?, date_naissance=?, sexe=?, lieu_naissance=?,
                    id_etab_courant=?, statut='actif'
             WHERE niu=?",
            [...$p, $id_e, $niu]
        );
    } else {
        // NIU hors registre (repli mono-école antérieur, import, saisie manuelle)
        assoc_exec(
            "INSERT INTO eleve_niu (niu, nom, prenom, date_naissance, sexe, lieu_naissance,
                    id_etab_origine, id_etab_courant, statut)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
            [$niu, ...$p, $id_e, $id_e]
        );
    }
    assoc_exec(
        "INSERT INTO eleve_niu_mouvement (niu, id_etab_cible, type, par) VALUES (?, ?, 'inscription', ?)",
        [$niu, $id_e, 'ecole:' . ($ec['code'] ?? '?')]
    );
}

// ── Affectation d'un agent dans la base d'une école ─────────────────
//  Écrit dans la base école CIBLE (via avec_ecole) : crée/retrouve la
//  ligne `enseignant`, crée le compte `user` si absent, puis enregistre
//  l'affectation au central. Retourne [ok, message, login, mdp_temporaire].
//
//  $ident : ['nom','prenom','sexe','date_naiss','tel','email']
function affecter_agent(string $matricule, int $id_etab_cible, string $fonction, array $ident): array {
    if (!annuaire_dispo()) return [false, 'Annuaire indisponible.', null, null];
    $fonction = in_array($fonction, ['DIRECTEUR', 'ENSEIGNANT', 'SECRETAIRE', 'COMPTABLE'], true) ? $fonction : 'ENSEIGNANT';

    // 1. personnel central (créé si absent)
    if (!assoc_val("SELECT COUNT(*) FROM personnel WHERE matricule=?", [$matricule])) {
        assoc_exec(
            "INSERT INTO personnel (matricule, nom, prenom, date_naissance, sexe, tel, email)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$matricule, $ident['nom'] ?? '', $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
             $ident['sexe'] ?? null, $ident['tel'] ?? null, $ident['email'] ?? null]
        );
    }

    // 2. écriture dans la base école cible
    $mdp_clair = bin2hex(random_bytes(4));   // mot de passe temporaire
    $res = avec_ecole($id_etab_cible, function (mysqli $l) use ($ident, $fonction, $matricule, $mdp_clair) {
        // enseignant existant ? (mat_ens = matricule central, sinon identité)
        $ex = ecole_one($l, "SELECT matricule_ens FROM enseignant WHERE mat_ens=? LIMIT 1", [$matricule])
           ?? ecole_one($l, "SELECT matricule_ens FROM enseignant WHERE nom_ens=? AND COALESCE(prenom_ens,'')=? LIMIT 1",
                        [$ident['nom'] ?? '', $ident['prenom'] ?? '']);
        if ($ex) {
            $mat_ens = (int) $ex['matricule_ens'];
            ecole_exec($l, "UPDATE enseignant SET id_fonction=?, statut_ens='actif' WHERE matricule_ens=?",
                       [$fonction, $mat_ens]);
        } else {
            ecole_exec($l,
                "INSERT INTO enseignant (nom_ens, prenom_ens, sexe_ens, date_naiss_ens, tel_ens, mail_ens,
                        mat_ens, id_fonction, statut_ens)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'actif')",
                [$ident['nom'] ?? '', $ident['prenom'] ?? null, $ident['sexe'] ?? null, $ident['date_naiss'] ?? null,
                 $ident['tel'] ?? null, $ident['email'] ?? null, $matricule, $fonction]
            );
            $mat_ens = (int) mysqli_insert_id($l);
        }

        // compte user ?
        $u = ecole_one($l, "SELECT id_user, login_user FROM user WHERE matricule_ens=? LIMIT 1", [$mat_ens]);
        if ($u) {
            return ['mat_ens' => $mat_ens, 'id_user' => (int) $u['id_user'],
                    'login' => $u['login_user'], 'nouveau_compte' => false];
        }
        $slug = strtolower(preg_replace('/[^a-z0-9]/i', '',
            substr($ident['prenom'] ?? '', 0, 1) . ($ident['nom'] ?? 'agent')));
        $slug  = $slug !== '' ? $slug : 'agent';
        $login = $slug; $i = 1;
        while (ecole_one($l, "SELECT id_user FROM user WHERE login_user=?", [$login])) { $login = $slug . (++$i); }
        ecole_exec($l, "INSERT INTO user (login_user, pwd_user, matricule_ens) VALUES (?, ?, ?)",
                   [$login, password_hash($mdp_clair, PASSWORD_DEFAULT), $mat_ens]);
        return ['mat_ens' => $mat_ens, 'id_user' => (int) mysqli_insert_id($l),
                'login' => $login, 'nouveau_compte' => true];
    });

    // 3. affectation centrale (upsert)
    $aff = assoc_one("SELECT id FROM personnel_affectation WHERE matricule=? AND id_etablissement=?",
                     [$matricule, $id_etab_cible]);
    if ($aff) {
        assoc_exec(
            "UPDATE personnel_affectation SET fonction=?, matricule_ens_local=?, id_user_local=?,
                    date_fin=NULL, actif=1 WHERE id=?",
            [$fonction, $res['mat_ens'], $res['id_user'], $aff['id']]
        );
    } else {
        assoc_exec(
            "INSERT INTO personnel_affectation
                (matricule, id_etablissement, fonction, matricule_ens_local, id_user_local, date_debut, actif)
             VALUES (?, ?, ?, ?, ?, CURDATE(), 1)",
            [$matricule, $id_etab_cible, $fonction, $res['mat_ens'], $res['id_user']]
        );
    }

    $m = "Affecté. Compte « {$res['login']} »";
    $m .= $res['nouveau_compte'] ? " créé — mot de passe temporaire : $mdp_clair (à changer)." : " (compte existant réutilisé).";
    return [true, $m, $res['login'], $res['nouveau_compte'] ? $mdp_clair : null];
}

// Clôt une affectation (désactive le compte user dans la base école source).
function cloturer_affectation(int $id_affectation): void {
    if (!annuaire_dispo()) return;
    $a = assoc_one("SELECT * FROM personnel_affectation WHERE id=?", [$id_affectation]);
    if (!$a) return;
    assoc_exec("UPDATE personnel_affectation SET actif=0, date_fin=CURDATE() WHERE id=?", [$id_affectation]);
    if ($a['matricule_ens_local']) {
        avec_ecole((int) $a['id_etablissement'], function ($l) use ($a) {
            $st = mysqli_prepare($l, "UPDATE enseignant SET statut_ens='inactif' WHERE matricule_ens=?");
            mysqli_stmt_bind_param($st, 's', $a['matricule_ens_local']);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        });
    }
}

function niu_synchroniser_identite(string $niu, array $ident): void {
    if (!annuaire_dispo() || trim($niu) === '') return;
    if (!assoc_val("SELECT COUNT(*) FROM eleve_niu WHERE niu=?", [$niu])) return;
    assoc_exec(
        "UPDATE eleve_niu SET nom=?, prenom=?, date_naissance=?, sexe=?, lieu_naissance=? WHERE niu=?",
        [
            $ident['nom'] ?? null, $ident['prenom'] ?? null, $ident['date_naiss'] ?? null,
            $ident['sexe'] ?? null, $ident['lieu_naiss'] ?? null, $niu,
        ]
    );
}
