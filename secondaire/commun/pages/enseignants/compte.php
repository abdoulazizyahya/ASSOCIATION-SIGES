<?php
// secondaire/commun/pages/enseignants/compte.php — compte de connexion d'un membre du
// personnel, géré depuis sa fiche (Ressources humaines → Enseignants, 17/10/2026) :
// créer le compte par défaut, réinitialiser le mot de passe (= identifiant), modifier
// identifiant / rôle / état / mot de passe ; depuis la liste, créer tous les comptes
// manquants. Réservé à l'administrateur, au propriétaire, au fondateur et au proviseur
// (sec_peut_gerer_comptes) ; un proviseur ne touche jamais un compte de rang égal ou
// supérieur (sec_compte_gerable).
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') rediriger('secondaire/commun/pages/enseignants/liste.php');
csrf_verifier();
if (!sec_peut_gerer_comptes()) { flash_set('erreur', 'Gestion des comptes réservée à l\'administrateur et au chef d\'établissement.'); rediriger('secondaire/commun/pages/enseignants/liste.php'); }

$action = post('action');
$mat    = (int) post('mat');
$retour = $mat ? 'secondaire/commun/pages/enseignants/voir.php?id=' . $mat . '#compte' : 'secondaire/commun/pages/enseignants/liste.php';
$journal = fn(string $a, string $cible) => function_exists('journaliser_action') ? journaliser_action($a, null, $cible) : null;

// ── Tous les comptes manquants (liste du personnel) ───────────────────────
if ($action === 'creer_manquants') {
    $crees = [];
    foreach (db_all("SELECT e.matricule_ens FROM enseignant e
                     WHERE NOT EXISTS (SELECT 1 FROM utilisateur u WHERE u.matricule_ens=e.matricule_ens)
                       AND " . sec_sql_ens_en_service('e')) as $e) {   // pas de compte pour un ancien (muté, parti…)
        if ($u = sec_creer_compte_fiche((int) $e['matricule_ens'])) $crees[] = $u['login'];
    }
    if ($crees) $journal('comptes_personnel_crees', implode(', ', $crees));
    flash_set('succes', $crees ? count($crees) . ' compte(s) créé(s) — mot de passe = identifiant : ' . implode(', ', $crees) . '.' : 'Tout le personnel a déjà un compte.');
    rediriger($retour);
}

// ── Créer le compte d'une fiche ───────────────────────────────────────────
if ($action === 'creer') {
    $u = $mat ? sec_creer_compte_fiche($mat) : null;
    if ($u) {
        $journal('compte_personnel_cree', $u['login']);
        flash_set('succes', 'Compte créé : identifiant « ' . $u['login'] . ' », mot de passe identique (à changer à la première connexion), rôle ' . libelle_role($u['role']) . '.');
    } else {
        flash_set('erreur', 'Ce membre du personnel a déjà un compte.');
    }
    rediriger($retour);
}

// ── Actions sur un compte existant ────────────────────────────────────────
$u = db_one("SELECT * FROM utilisateur WHERE id=?", [(int) post('id')]);
if (!$u || !sec_compte_gerable($u) || ($mat && (int) $u['matricule_ens'] !== $mat)) {
    flash_set('erreur', 'Compte introuvable ou hors de votre périmètre.');
    rediriger($retour);
}
$est_soi = (int) $u['id'] === (int) ($_SESSION['user_id'] ?? 0);

if ($action === 'reinitialiser') {
    db_exec("UPDATE utilisateur SET mot_de_passe=?, actif=1 WHERE id=?", [password_hash($u['login'], PASSWORD_DEFAULT), $u['id']]);
    $journal('mot_de_passe_reinitialise', $u['login']);
    flash_set('succes', 'Mot de passe réinitialisé : il est maintenant identique à l\'identifiant « ' . $u['login'] . ' ». La personne doit le changer à sa prochaine connexion (Mon compte).');
    rediriger($retour);
}

if ($action === 'modifier') {
    $login = mb_strtolower(trim(post('login')));
    $role  = post('role');
    $actif = (int) (bool) post('actif');
    $mdp   = post('mot_de_passe');
    if ($est_soi) { $role = $u['role']; $actif = 1; }   // ni son propre rôle, ni sa propre désactivation
    if (!preg_match('/^[a-z0-9._-]{3,80}$/', $login)) {
        flash_set('erreur', 'Identifiant invalide : 3 caractères au moins, lettres minuscules, chiffres, point, tiret.');
        rediriger($retour);
    }
    if (db_val("SELECT 1 FROM utilisateur WHERE LOWER(login)=? AND id<>?", [$login, $u['id']])) {
        flash_set('erreur', 'L\'identifiant « ' . $login . ' » est déjà utilisé.');
        rediriger($retour);
    }
    if ($role !== $u['role']) {
        if (!in_array($role, sec_roles_attribuables(), true)) { flash_set('erreur', 'Rôle non autorisé pour votre profil.'); rediriger($retour); }
        if ($role === 'PROVISEUR' && ($refus = refus_second_chef((int) $u['id'])) !== '') { flash_set('erreur', $refus); rediriger($retour); }
    }
    if ($mdp !== '' && mb_strlen($mdp) < 4) { flash_set('erreur', 'Le mot de passe doit faire au moins 4 caractères.'); rediriger($retour); }
    db_exec("UPDATE utilisateur SET login=?, role=?, actif=? WHERE id=?", [$login, $role, $actif, $u['id']]);
    if ($mdp !== '') db_exec("UPDATE utilisateur SET mot_de_passe=? WHERE id=?", [password_hash($mdp, PASSWORD_DEFAULT), $u['id']]);
    $journal('compte_personnel_modifie', $login);
    flash_set('succes', 'Compte mis à jour' . ($mdp !== '' ? ' (nouveau mot de passe enregistré)' : '') . '.');
    rediriger($retour);
}

rediriger($retour);
