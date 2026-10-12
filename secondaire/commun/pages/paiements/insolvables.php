<?php
// secondaire/commun/pages/paiements/insolvables.php — l'onglet "Insolvables" a été intégré
// dans secondaire/commun/pages/paiements/rapport.php (avec Paiements/Statut par frais/
// Statistiques) ; ce fichier ne fait plus que rediriger vers ce nouvel
// emplacement en conservant les paramètres, pour que le lien de menu
// existant (noyau/layout/header.php) et d'éventuels favoris continuent de
// fonctionner.
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../../noyau/fonctions.php';

$params = $_GET;
$params['onglet'] = 'insolvables';
rediriger('secondaire/commun/pages/paiements/rapport.php?' . http_build_query($params));
