<?php
// noyau/ajax/geo_position.php — reçoit la position GPS envoyée par le
// navigateur juste après une connexion (audit_gps_script(), fonctions.php)
// et la reporte sur la ligne « connexion » du journal d'audit
// (audit_gps_enregistrer(), bd/lib/audit.php — 31/10/2026).
// Position obligatoire (audit_gps_exiger) : seule une position enregistrée
// libère l'accès ; un refus le laisse bloqué ; 3 échecs techniques (appareil
// sans localisation) le rendent. Réponse : {ok, libre}.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../../bd/lib/audit.php';
ecole_session_demarrer();

header('Content-Type: application/json; charset=utf-8');

$g = $_SESSION['audit_gps'] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($g)
    || !hash_equals((string) ($g['jeton'] ?? ''), (string) ($_POST['jeton'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
$position = isset($_POST['lat'], $_POST['lon']) && is_numeric($_POST['lat']) && is_numeric($_POST['lon']);
// Position trop imprécise (estimation d'après l'IP, ± 50 km) : enregistrée
// faute de mieux, mais compte comme un échec — l'accès reste bloqué jusqu'à
// une vraie position ou 3 essais (activer la localisation Windows / le Wi-Fi).
$imprecis = $position && (int) ($_POST['precision'] ?? 0) > AUDIT_GPS_PRECISION_MAX;
if (($position && !$imprecis) || !empty($_POST['facultatif'])) {   // demande facultative (comptes propriétaires) : une seule fois
    unset($_SESSION['audit_gps']);
} elseif (($_POST['refus'] ?? '') !== 'refus') {
    $_SESSION['audit_gps']['echecs'] = (int) ($g['echecs'] ?? 0) + 1;
    if ($_SESSION['audit_gps']['echecs'] >= 3) unset($_SESSION['audit_gps']);
}
$libre = !isset($_SESSION['audit_gps']);
session_write_close();   // le géocodage peut prendre 1-3 s : ne pas bloquer les autres pages

$ok = $position && audit_gps_enregistrer((int) $g['id'], (float) $_POST['lat'], (float) $_POST['lon'], (int) ($_POST['precision'] ?? 0));
echo json_encode(['ok' => $ok, 'libre' => $libre, 'imprecis' => $imprecis]);
