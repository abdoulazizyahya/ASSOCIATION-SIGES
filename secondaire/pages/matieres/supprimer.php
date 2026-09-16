<?php
// secondaire/pages/matieres/supprimer.php — suppression d'une matière du
// catalogue (porté de LAM_ABZ/pages/matieres/supprimer.php).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR']);
csrf_verifier();

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    db_exec("DELETE FROM matiere WHERE id=?", [$id]);
    flash_set('succes', 'Matière supprimée.');
}
rediriger('secondaire/pages/matieres/liste.php?onglet=catalogue');
