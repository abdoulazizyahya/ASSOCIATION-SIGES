<?php
/**
 * Traite la décision (valider/rejeter) d'une demande de document, à l'étape
 * Censeur ou Proviseur selon le statut courant. POST uniquement.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_connexion();

$role = role_connecte();
if (!in_array($role, ['CENSEUR','PROVISEUR','ADMIN'])) { flash_set('erreur','Accès refusé.'); rediriger('dashboard.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { rediriger('secondaire/pages/demandes/index.php'); }

csrf_verifier();
$uid = (int)($_SESSION['user_id'] ?? 0);

$id          = (int)($_POST['id'] ?? 0);
$action      = $_POST['action'] ?? '';
$commentaire = trim($_POST['commentaire'] ?? '');

if (!$id || !in_array($action, ['valider','rejeter'], true)) {
    flash_set('erreur', 'Requête invalide.'); rediriger('secondaire/pages/demandes/index.php');
}

$d = db_one("SELECT * FROM demande_document WHERE id=?", [$id]);
if (!$d) { flash_set('erreur', 'Demande introuvable.'); rediriger('secondaire/pages/demandes/index.php'); }

// Détermine si le rôle courant est habilité à agir sur l'étape actuelle de la demande.
$etape_censeur   = $d['statut'] === 'en_attente_censeur';
$etape_proviseur = $d['statut'] === 'en_attente_proviseur';

$autorise = ($role === 'ADMIN' && ($etape_censeur || $etape_proviseur))
    || ($role === 'CENSEUR'   && $etape_censeur)
    || ($role === 'PROVISEUR' && $etape_proviseur);

if (!$autorise) {
    flash_set('erreur', 'Cette demande n\'est plus (ou pas encore) à traiter à votre niveau — elle a peut-être déjà été traitée entre-temps.');
    rediriger('secondaire/pages/demandes/index.php');
}

if ($action === 'rejeter' && $commentaire === '') {
    flash_set('erreur', 'Un motif est requis pour rejeter une demande.');
    rediriger('secondaire/pages/demandes/index.php');
}

// Utilisateur de l'enseignant initiateur, pour la notification.
$id_ens_user = db_val("SELECT id FROM utilisateur WHERE matricule_ens=? LIMIT 1", [$d['matricule_ens']]);
$type_lib    = libelle_type_demande($d['type_document']);

// L'ADMIN agit "au nom" de l'étape en cours (censeur ou proviseur), selon le statut réel.
$agit_comme_censeur = $etape_censeur;

if ($agit_comme_censeur) {
    if ($action === 'valider') {
        db_exec("UPDATE demande_document SET statut='en_attente_proviseur', id_censeur=?, date_censeur=NOW(), commentaire_censeur=? WHERE id=?",
                [$uid, $commentaire ?: null, $id]);
        notifier((int)$id_ens_user, "Votre demande de $type_lib a été approuvée par le Censeur et est en attente de validation du Proviseur.", 'secondaire/pages/demandes/index.php');
        notifier_role('PROVISEUR', "Une demande de $type_lib est en attente de votre validation.", 'secondaire/pages/demandes/index.php');
        flash_set('succes', 'Demande approuvée et transmise au Proviseur.');
    } else {
        db_exec("UPDATE demande_document SET statut='rejetee_censeur', id_censeur=?, date_censeur=NOW(), commentaire_censeur=? WHERE id=?",
                [$uid, $commentaire, $id]);
        notifier((int)$id_ens_user, "Votre demande de $type_lib a été rejetée par le Censeur. Motif : $commentaire", 'secondaire/pages/demandes/index.php');
        flash_set('succes', 'Demande rejetée.');
    }
} else { // étape proviseur
    if ($action === 'valider') {
        db_exec("UPDATE demande_document SET statut='validee', id_proviseur=?, date_proviseur=NOW(), commentaire_proviseur=? WHERE id=?",
                [$uid, $commentaire ?: null, $id]);
        notifier((int)$id_ens_user, "Votre demande de $type_lib a été validée par le Proviseur. Vous pouvez désormais la consulter et l'imprimer.", 'secondaire/pages/demandes/index.php');
        flash_set('succes', 'Demande validée. L\'enseignant peut maintenant télécharger son document.');
    } else {
        db_exec("UPDATE demande_document SET statut='rejetee_proviseur', id_proviseur=?, date_proviseur=NOW(), commentaire_proviseur=? WHERE id=?",
                [$uid, $commentaire, $id]);
        notifier((int)$id_ens_user, "Votre demande de $type_lib a été rejetée par le Proviseur. Motif : $commentaire", 'secondaire/pages/demandes/index.php');
        flash_set('succes', 'Demande rejetée.');
    }
}

rediriger('secondaire/pages/demandes/index.php');
