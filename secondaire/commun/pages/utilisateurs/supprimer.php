<?php
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../noyau/connexion.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';
exiger_role(['ADMIN', 'FONDATEUR']);

if (!isset($_GET['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'])) {
    flash_set('erreur', 'Jeton invalide.');
    rediriger('secondaire/commun/pages/utilisateurs/liste.php');
}
$id = (int)($_GET['id'] ?? 0);
if ($id && $id != ($_SESSION['user_id'] ?? null)) {
    db_exec("DELETE FROM utilisateur WHERE id=?", [$id]);
    flash_set('succes', 'Utilisateur supprimé.');
} else {
    flash_set('erreur', 'Impossible de supprimer votre propre compte.');
}
rediriger('secondaire/commun/pages/utilisateurs/liste.php');
