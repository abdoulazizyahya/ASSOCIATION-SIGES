<?php
// association/personnel/agents_json.php — liste des agents d'une école (JSON)
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
exiger_membre_association();
header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { echo '[]'; exit; }

$rows = avec_ecole($id, fn($l) => ecole_all($l,
    "SELECT matricule_ens, TRIM(CONCAT(nom_ens,' ',COALESCE(prenom_ens,''))) AS nom,
            COALESCE(id_fonction,'ENSEIGNANT') AS fonction
     FROM enseignant WHERE COALESCE(statut_ens,'actif')='actif' ORDER BY nom_ens", []));

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
