<?php
// association/personnel/personnel_libre_json.php — membres du personnel d'une
// école qui n'ont PAS encore de compte de connexion (JSON). Alimente le
// formulaire « Créer un compte » de l'onglet Comptes (personnel/liste.php).
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_superadmin_association();
header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['ec'] ?? $_GET['id'] ?? 0);
echo json_encode(
    ['agents' => $id ? assoc_ecole_personnel_sans_compte($id) : []],
    JSON_UNESCAPED_UNICODE
);
