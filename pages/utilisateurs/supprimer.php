<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$id = (int)($_GET['id'] ?? 0);
$fct_cible = (string) db_val("SELECT e.id_fonction FROM user u JOIN enseignant e ON e.matricule_ens=u.matricule_ens WHERE u.id_user=?", [$id]);
if ($id === (int)($_SESSION['user_id'] ?? 0)) {
    flash_set('erreur', 'Vous ne pouvez pas supprimer votre propre compte.');
} elseif (!compte_gerable($fct_cible, $id)) {
    flash_set('erreur', refus_compte_non_gerable($fct_cible));   // directeur : son personnel seulement
} else {
    $login_suppr = db_val("SELECT login_user FROM user WHERE id_user=?", [$id]);
    db_exec("DELETE FROM user WHERE id_user=?", [$id]);
    journaliser_action('utilisateur_suppr', null, (string) ($login_suppr ?? ('#' . $id)));
    flash_set('succes', 'Compte supprimé.');
}
rediriger('pages/utilisateurs/liste.php');
