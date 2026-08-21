<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$id = (int)($_GET['id'] ?? 0);
$nb_inscrits = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE IDClasses=?", [$id]);
if ($nb_inscrits > 0) {
    flash_set('erreur', 'Impossible : des élèves sont inscrits dans cette classe.');
} else {
    db_exec("DELETE FROM classe WHERE IDClasses=?", [$id]);
    flash_set('succes', 'Classe supprimée.');
}
rediriger('pages/classes/liste.php');
