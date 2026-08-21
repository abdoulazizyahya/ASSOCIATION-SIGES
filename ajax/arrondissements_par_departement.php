<?php
// ajax/arrondissements_par_departement.php — retourne les arrondissements
// d'un département en JSON, pour peupler dynamiquement un select
// « Arrondissement » quand « Département » change (widget assets/js/
// lieu-cascade.js). Complète ajax/departements_par_region.php — même
// convention.
//
// Accepte soit id_departement (int, code_depart), soit nom_departement
// (texte, comparaison exacte sur intitule_depart).
//
// ob_start() dès le début : capture tout warning/notice PHP parasite pour
// qu'il ne casse jamais le parsing JSON côté navigateur.
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();

$id_departement  = (int) ($_GET['id_departement'] ?? 0);
$nom_departement = trim($_GET['nom_departement'] ?? '');

if (!$id_departement && $nom_departement !== '') {
    $d = db_one("SELECT code_depart FROM departement WHERE intitule_depart = ?", [$nom_departement]);
    $id_departement = $d ? (int) $d['code_depart'] : 0;
}

$arrondissements = $id_departement
    ? db_all(
        "SELECT code_arrond AS id, intitule_arrond AS nom
         FROM arrondissement WHERE code_depart = ? ORDER BY intitule_arrond",
        [$id_departement]
      )
    : [];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($arrondissements, JSON_UNESCAPED_UNICODE);
