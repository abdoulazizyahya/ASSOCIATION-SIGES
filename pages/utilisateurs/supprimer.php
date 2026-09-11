<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$id = (int)($_GET['id'] ?? 0);
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    flash_set('erreur', 'Vous ne pouvez pas supprimer votre propre compte.');
} else {
    $login_suppr = db_val("SELECT login_user FROM user WHERE id_user=?", [$id]);
    db_exec("DELETE FROM user WHERE id_user=?", [$id]);
    journaliser_action('utilisateur_suppr', null, (string) ($login_suppr ?? ('#' . $id)));
    flash_set('succes', 'Compte supprimé.');
}
rediriger('pages/utilisateurs/liste.php');
