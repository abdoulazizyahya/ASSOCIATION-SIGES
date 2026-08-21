<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_role(['DIRECTEUR','SECRETAIRE']);
csrf_verifier();

$id    = (int)($_GET['id'] ?? 0);
$eleve = db_one("SELECT id_eleve, statut FROM eleve WHERE id_eleve=?", [$id]);
if ($eleve) {
    $nouveau = $eleve['statut'] === 'actif' ? 'desactive' : 'actif';
    db_exec("UPDATE eleve SET statut=? WHERE id_eleve=?", [$nouveau, $id]);
    $msg = $nouveau === 'actif'
        ? 'Élève réactivé — il réapparaît dans les effectifs.'
        : 'Élève désactivé — exclu des effectifs et des listes.';
    flash_set('succes', $msg);
} else {
    flash_set('erreur', 'Élève introuvable.');
}
rediriger('pages/eleves/liste.php');
