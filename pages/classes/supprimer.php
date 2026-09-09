<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR']);
csrf_verifier();

$id = (int)($_GET['id'] ?? 0);
// Suppression autorisée UNIQUEMENT si la classe n'a JAMAIS eu d'inscrit
// (toutes années confondues) — sinon on orphelinerait des inscriptions,
// notes, bulletins… rattachés à cette classe. Le bouton est déjà désactivé
// côté liste (pages/classes/liste.php) dans ce cas ; ce contrôle serveur
// reste la garde réelle (URL forgée, requête AJAX…).
$classe      = db_one("SELECT DesignationClasses FROM classe WHERE IDClasses=?", [$id]);
$nb_inscrits = (int) db_val("SELECT COUNT(*) FROM inscrire WHERE IDClasses=?", [$id]);
if (!$classe) {
    flash_set('erreur', 'Classe introuvable.');
} elseif ($nb_inscrits > 0) {
    flash_set('erreur', "Suppression impossible : $nb_inscrits inscription(s) enregistrée(s) dans cette classe. Retirez d'abord les élèves.");
} else {
    db_exec("DELETE FROM classe WHERE IDClasses=?", [$id]);
    flash_set('succes', 'Classe « ' . $classe['DesignationClasses'] . ' » supprimée.');
}
rediriger('pages/classes/liste.php');
