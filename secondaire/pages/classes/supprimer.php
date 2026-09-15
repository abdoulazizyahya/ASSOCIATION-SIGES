<?php
// secondaire/pages/classes/supprimer.php — archive une classe (pas de
// suppression réelle : archivee=1, exclue des listes actives). Porté de
// LAM_ABZ, aligné sur la convention csrf_verifier() du reste de SIGES.
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR']);
csrf_verifier();

$id = (int) ($_GET['id'] ?? 0);
if ($id) {
    db_exec("UPDATE classe SET archivee=1 WHERE id=?", [$id]);
    flash_set('succes', 'Classe archivée.');
}
rediriger('secondaire/pages/classes/liste.php');
