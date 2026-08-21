<?php
// ajax/signature_position_get.php — retourne en JSON la position/taille (en
// % du cadre) déjà enregistrée pour un type de document, ou une position
// générique par défaut si rien n'a encore été configuré — préremplit la
// fenêtre modale de positionnement (layout/footer.php::ouvrirPositionSignature()).
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();

$type_document = (string)($_GET['type'] ?? '');

$defaut = ['x_pct' => 65, 'y_pct' => 80, 'w_pct' => 15, 'h_pct' => null];
$pos = $type_document !== '' ? signature_position_lookup($type_document, $defaut) : $defaut;

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'x_pct' => (float)$pos['x_pct'],
    'y_pct' => (float)$pos['y_pct'],
    'w_pct' => (float)$pos['w_pct'],
]);
