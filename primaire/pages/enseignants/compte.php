<?php
// primaire/pages/enseignants/compte.php — compte de connexion d'un membre du personnel,
// géré depuis sa fiche (Ressources humaines → Enseignant(e)s, 17/10/2026, mêmes règles
// que le secondaire) : créer le compte par défaut (identifiant tiré du nom, mot de passe
// identique), réinitialiser le mot de passe (= identifiant), modifier identifiant / état /
// mot de passe ; depuis la liste, créer tous les comptes manquants. Le rôle du compte est
// la fonction de la fiche (il change avec elle). Droits : fiche_gerable() / compte_gerable()
// — le directeur gère son personnel, le fondateur et l'association gèrent tout.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
exiger_role(['DIRECTEUR', 'FONDATEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('primaire/pages/enseignants/liste.php');
csrf_verifier();

$action = post('action');
$mat    = (int) post('mat');
$retour = $mat ? 'primaire/pages/enseignants/voir.php?mat=' . $mat . '#compte' : 'primaire/pages/enseignants/liste.php';

// ── Tous les comptes manquants (liste du personnel) ───────────────────────
if ($action === 'creer_manquants') {
    $crees = [];
    foreach (db_all("SELECT e.matricule_ens, e.id_fonction FROM enseignant e
                     WHERE COALESCE(e.statut_ens, 'actif') = 'actif'
                       AND NOT EXISTS (SELECT 1 FROM user u WHERE u.matricule_ens = e.matricule_ens)") as $e) {
        if (!fiche_gerable((int) $e['matricule_ens'], (string) $e['id_fonction'])) continue;
        if ($u = prim_creer_compte_fiche((int) $e['matricule_ens'])) $crees[] = $u['login_user'];
    }
    if ($crees) journaliser_action('comptes_personnel_crees', null, implode(', ', $crees));
    flash_set('succes', $crees ? count($crees) . ' compte(s) créé(s) — mot de passe = identifiant : ' . implode(', ', $crees) . '.' : 'Tout le personnel actif a déjà un compte.');
    rediriger($retour);
}

$fiche = $mat ? db_one("SELECT matricule_ens, id_fonction FROM enseignant WHERE matricule_ens=?", [$mat]) : null;
if (!$fiche || !fiche_gerable($mat, (string) $fiche['id_fonction'])) {
    flash_set('erreur', $fiche ? refus_compte_non_gerable((string) $fiche['id_fonction']) : 'Membre du personnel introuvable.');
    rediriger($retour);
}

// ── Créer le compte de la fiche ───────────────────────────────────────────
if ($action === 'creer') {
    if ($u = prim_creer_compte_fiche($mat)) {
        journaliser_action('utilisateur_cree', null, $u['login_user']);
        flash_set('succes', 'Compte créé : identifiant « ' . $u['login_user'] . ' », mot de passe identique (à changer à la première connexion).');
    } else {
        flash_set('erreur', 'Ce membre du personnel a déjà un compte.');
    }
    rediriger($retour);
}

// ── Actions sur le compte existant ────────────────────────────────────────
$u = prim_compte_de_fiche($mat);
if (!$u || (int) $u['id_user'] !== (int) post('id') || !compte_gerable((string) $fiche['id_fonction'], (int) $u['id_user'])) {
    flash_set('erreur', 'Compte introuvable ou hors de votre périmètre.');
    rediriger($retour);
}
$est_soi = (int) $u['id_user'] === (int) ($_SESSION['user_id'] ?? 0);

if ($action === 'reinitialiser') {
    db_exec("UPDATE user SET pwd_user=?, actif=1 WHERE id_user=?", [password_hash($u['login_user'], PASSWORD_DEFAULT), $u['id_user']]);
    journaliser_action('mot_de_passe_reinit', null, $u['login_user']);
    flash_set('succes', 'Mot de passe réinitialisé : il est maintenant identique à l\'identifiant « ' . $u['login_user'] . ' ». La personne doit le changer à sa prochaine connexion (Mon compte).');
    rediriger($retour);
}

if ($action === 'modifier') {
    $login = mb_strtolower(trim(post('login')));
    $actif = $est_soi ? 1 : (int) (bool) post('actif');   // jamais sa propre désactivation
    $mdp   = post('mot_de_passe');
    if (!preg_match('/^[a-z0-9._@-]{3,50}$/', $login)) {
        flash_set('erreur', 'Identifiant invalide : 3 caractères au moins, lettres minuscules, chiffres, point, tiret.');
        rediriger($retour);
    }
    if (db_val("SELECT 1 FROM user WHERE LOWER(login_user)=? AND id_user<>?", [$login, $u['id_user']])) {
        flash_set('erreur', 'L\'identifiant « ' . $login . ' » est déjà utilisé.');
        rediriger($retour);
    }
    if ($mdp !== '' && mb_strlen($mdp) < 4) { flash_set('erreur', 'Le mot de passe doit faire au moins 4 caractères.'); rediriger($retour); }
    db_exec("UPDATE user SET login_user=?, actif=? WHERE id_user=?", [$login, $actif, $u['id_user']]);
    if ($mdp !== '') db_exec("UPDATE user SET pwd_user=? WHERE id_user=?", [password_hash($mdp, PASSWORD_DEFAULT), $u['id_user']]);
    journaliser_action('compte_personnel_modifie', null, $login);
    flash_set('succes', 'Compte mis à jour' . ($mdp !== '' ? ' (nouveau mot de passe enregistré)' : '') . '.');
    rediriger($retour);
}

rediriger($retour);
