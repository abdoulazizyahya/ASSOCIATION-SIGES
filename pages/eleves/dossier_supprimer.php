<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);
csrf_verifier();

$id  = (int)($_GET['id'] ?? 0);
$doc = db_one("SELECT * FROM dossier_eleve WHERE id=?", [$id]);
if ($doc) {
    $chemin = __DIR__ . '/../../assets/uploads/dossiers_eleves/' . $doc['fichier'];
    db_exec("DELETE FROM dossier_eleve WHERE id=?", [$id]);
    if (is_file($chemin)) @unlink($chemin);
    flash_set('succes', 'Document retiré du dossier.');
    rediriger('pages/eleves/voir.php?id=' . (int)$doc['id_eleve']);
}
rediriger('pages/eleves/liste.php');
