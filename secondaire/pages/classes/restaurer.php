<?php
// secondaire/pages/classes/restaurer.php — désarchive une classe (archivee=0,
// redevient visible/utilisable partout). Pendant de supprimer.php — même
// contrôle d'accès, même convention csrf_verifier(). Demande explicite du
// 17/09/2026 (onglet « Classes archivées » de liste.php).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'FONDATEUR']);
csrf_verifier();

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    db_exec("UPDATE classe SET archivee=0 WHERE id=?", [$id]);
    flash_set('succes', 'Classe désarchivée.');
}
rediriger('secondaire/pages/classes/liste.php?onglet=archivees');
