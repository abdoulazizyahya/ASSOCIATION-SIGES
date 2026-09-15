<?php
// secondaire/pages/eleves/statut.php — bascule actif/désactivé (GET+csrf,
// même convention que pages/eleves/statut.php côté primaire).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'SG', 'SECRETAIRE']);
csrf_verifier();

$id    = (int) ($_GET['id'] ?? 0);
$eleve = $id ? db_one("SELECT id, nom, statut FROM eleve WHERE id=?", [$id]) : null;
if (!$eleve) { flash_set('erreur', 'Élève introuvable.'); rediriger('secondaire/pages/eleves/liste.php'); }

$nouveau = $eleve['statut'] === 'actif' ? 'desactive' : 'actif';
db_exec("UPDATE eleve SET statut=? WHERE id=?", [$nouveau, $id]);
flash_set('succes', $nouveau === 'actif' ? 'Élève réactivé.' : 'Élève désactivé.');
rediriger('secondaire/pages/eleves/liste.php' . ($nouveau === 'desactive' ? '?statut=desactive' : ''));
