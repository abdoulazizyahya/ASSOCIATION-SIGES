<?php
// ajax/departements_par_region.php — retourne les départements d'une région
// en JSON, pour peupler dynamiquement un select « Département » quand
// « Région » change (widget assets/js/lieu-cascade.js).
//
// ⚠️ Réécrit le 10/08/2026 : la version précédente était un reste non
// adapté d'ABZ_MBE — elle interrogeait region.id/region.nom et
// departement.id/departement.nom/departement.id_region, des colonnes qui
// n'existent pas dans jaynitaare_v2_bd (vrai schéma : region.id_region/
// intitule_region, departement.code_depart/intitule_depart/code_region) —
// donc du code mort qui échouait silencieusement. Voir prompt_continuite_
// jaynitaare_v2.md pour le rappel général sur les fichiers copiés d'ABZ_MBE
// jamais adaptés.
//
// Accepte soit id_region (int), soit nom_region (texte, insensible à la casse
// n'est pas garanti — comparaison exacte sur intitule_region).
//
// ob_start() dès le début : capture tout warning/notice PHP parasite pour
// qu'il ne casse jamais le parsing JSON côté navigateur (sinon le select
// affiche « Erreur de chargement » même quand la requête a réellement réussi).
ob_start();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
exiger_connexion();

$id_region  = (int) ($_GET['id_region'] ?? 0);
$nom_region = trim($_GET['nom_region'] ?? '');

// École secondaire (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ) :
// region.id/nom + departement.id/nom/id_region — noms différents du
// primaire (region.id_region/intitule_region, departement.code_depart/
// intitule_depart/code_region). Même bug de fond que documenté plus haut,
// même correctif : brancher sur le type.
if (function_exists('type_enseignement_courant') && type_enseignement_courant() === 'secondaire') {
    if (!$id_region && $nom_region !== '') {
        $r = db_one("SELECT id FROM region WHERE nom = ?", [$nom_region]);
        $id_region = $r ? (int) $r['id'] : 0;
    }
    $deps = $id_region
        ? db_all("SELECT id, nom FROM departement WHERE id_region = ? ORDER BY nom", [$id_region])
        : [];
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($deps, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$id_region && $nom_region !== '') {
    $r = db_one("SELECT id_region FROM region WHERE intitule_region = ?", [$nom_region]);
    $id_region = $r ? (int) $r['id_region'] : 0;
}

$deps = $id_region
    ? db_all(
        "SELECT code_depart AS id, intitule_depart AS nom
         FROM departement WHERE code_region = ? ORDER BY intitule_depart",
        [$id_region]
      )
    : [];

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($deps, JSON_UNESCAPED_UNICODE);
