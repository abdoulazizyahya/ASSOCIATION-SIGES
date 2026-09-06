<?php
// ajax/ecole_identite.php — identité publique d'un établissement (nom, sigle,
// logo) à partir de son code. Sert à la page de connexion (login.php) : tant
// qu'aucune école n'est choisie l'accueil reste neutre ; dès qu'on
// sélectionne un établissement dans la liste, son logo et son nom
// s'affichent. N'expose que ce qui figure déjà dans le <select> de login.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: application/json; charset=utf-8');

if (!annuaire_dispo()) { echo json_encode(null); exit; }

$code = strtoupper(trim((string) ($_GET['code'] ?? '')));
$e = $code !== '' ? assoc_one("SELECT id, nom FROM etablissement WHERE code=? AND actif=1", [$code]) : null;
if (!$e) { echo json_encode(null); exit; }

try {
    $info = avec_ecole((int) $e['id'], fn($l) => ecole_one(
        $l, "SELECT Nom_Etab_Fr, Initial_Etab, logo FROM etablissement LIMIT 1"
    ));
} catch (\Throwable $ex) {
    $info = null;
}

$logo = $info['logo'] ?? '';
$logo_url = ($logo !== '' && is_file(__DIR__ . '/../assets/uploads/' . $logo))
    ? APP_URL . '/assets/uploads/' . $logo
    : null;

echo json_encode([
    'nom'   => $info['Nom_Etab_Fr'] ?: $e['nom'],
    'sigle' => $info['Initial_Etab'] ?? '',
    'logo'  => $logo_url,
]);
