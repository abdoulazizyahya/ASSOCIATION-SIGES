<?php
// compte/questions_securite_reporter.php — bouton « Plus tard » du bandeau
// questions de sécurité (noyau/layout/header.php). Masque le bandeau pour LA
// SESSION en cours uniquement : il réapparaîtra à la prochaine connexion
// tant que le compte n'a pas configuré ses 2 questions. Pas de CSRF (aucune
// écriture en base, juste un drapeau de session côté confort d'affichage).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
exiger_connexion();

$_SESSION['questions_securite_reportees'] = true;

$retour = (string) ($_GET['retour'] ?? 'noyau/dashboard.php');
// Anti-redirection ouverte : uniquement un chemin relatif interne.
if ($retour === '' || str_starts_with($retour, '//') || str_contains($retour, '://') || str_starts_with($retour, '/')) {
    $retour = 'noyau/dashboard.php';
}
rediriger($retour);
