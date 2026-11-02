<?php
// compte/langue.php?l=fr|en&retour=/chemin — change la langue de l'INTERFACE
// (cookie « siges_langue », 1 an) puis revient sur la page d'origine.
// Voir noyau/langue.php. Pas de session requise : utilisable aussi à la connexion.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/langue.php';

$l = (string) ($_GET['l'] ?? 'fr');
if (!isset(LANGUES_INTERFACE[$l])) $l = 'fr';

setcookie('siges_langue', $l, [
    'expires'  => time() + 365 * 86400,
    'path'     => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Retour : uniquement un chemin LOCAL (« /… », jamais « //hote » ni « http:… »).
$retour = (string) ($_GET['retour'] ?? '');
if ($retour === '' || $retour[0] !== '/' || str_starts_with($retour, '//') || str_contains($retour, '\\')
    || preg_match('/[\r\n]/', $retour)) {
    $retour = APP_URL . '/noyau/dashboard.php';
}
header('Location: ' . $retour);
exit;
