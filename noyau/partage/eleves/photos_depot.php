<?php
// noyau/partage/eleves/photos_depot.php — « Récupérer les photos du dossier »
// (AJAX POST, JSON) : traite le dossier de dépôt de l'école courante, voir
// _photos_depot_lib.php. Pages primaire/pages/eleves/ et
// secondaire/commun/pages/eleves/ du même nom : simple inclusion.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/_photos_depot_lib.php';
exiger_role(photos_roles());

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']); exit; }
csrf_verifier();
session_write_close();
try {
    $r = photos_depot_traiter(post('remplacer') === '1');
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => 'Traitement impossible : ' . $e->getMessage()]);
    exit;
}
echo json_encode(['ok' => true, 'dossier' => photos_depot_libelle()] + $r);
