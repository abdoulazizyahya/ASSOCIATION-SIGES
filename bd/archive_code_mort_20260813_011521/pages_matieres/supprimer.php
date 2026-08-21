<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['ADMIN','PROVISEUR','CENSEUR']);

if (!isset($_GET['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_GET['csrf'])) {
    flash_set('erreur', 'Jeton invalide.');
    rediriger('pages/matieres/liste.php');
}
$id = (int)($_GET['id'] ?? 0);
db_exec("DELETE FROM matiere WHERE id=?", [$id]);
flash_set('succes', 'Matière supprimée.');
rediriger('pages/matieres/liste.php?onglet=catalogue');
