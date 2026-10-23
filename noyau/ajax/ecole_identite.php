<?php
// noyau/ajax/ecole_identite.php — identité publique d'un établissement (nom, sigle,
// logo) à partir de son code. Sert à la page de connexion (compte/login.php) : tant
// qu'aucune école n'est choisie l'accueil reste neutre ; dès qu'on
// sélectionne un établissement dans la liste, son logo et son nom
// s'affichent. N'expose que ce qui figure déjà dans le <select> de login.php.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: application/json; charset=utf-8');

if (!annuaire_dispo()) { echo json_encode(null); exit; }

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$e = $code !== '' ? assoc_one("SELECT id, nom, type_enseignement FROM etablissement WHERE code=? AND actif=1", [$code]) : null;
if (!$e) { echo json_encode(null); exit; }

// École secondaire (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ) :
// etablissement.nom_fr/sigle, pas Nom_Etab_Fr/Initial_Etab (primaire) — sans
// ce branchement la requête échouait (colonne inconnue), rattrapée par le
// try/catch mais avec pour effet un aperçu SANS logo ni sigle sur compte/login.php
// pour toute école secondaire (jamais de Fatal error, juste une dégradation
// silencieuse — même famille de bug que les fichiers noyau/ajax/ voisins).
$secondaire = ($e['type_enseignement'] ?? 'primaire') === 'secondaire';
try {
    $info = avec_ecole((int) $e['id'], fn($l) => ecole_one(
        $l, $secondaire
            ? "SELECT nom_fr AS Nom_Etab_Fr, sigle AS Initial_Etab, logo FROM etablissement LIMIT 1"
            : "SELECT Nom_Etab_Fr, Initial_Etab, logo FROM etablissement LIMIT 1"
    ));
} catch (\Throwable $ex) {
    $info = null;
}

$logo = $info['logo'] ?? '';
$logo_url = ($logo !== '' && is_file(__DIR__ . '/../../assets/uploads/' . $logo))
    ? APP_URL . '/assets/uploads/' . $logo
    : null;

// Nom = celui de l'annuaire, le MÊME que dans la liste de compte/login.php
// (23/10/2026) : le nom saisi dans la base de l'école peut différer (base
// importée d'une autre école…) et faisait croire à un mélange d'écoles.
echo json_encode([
    'nom'   => $e['nom'],
    'sigle' => $info['Initial_Etab'] ?? '',
    'logo'  => $logo_url,
]);
