<?php
// pages/paie/mes_bulletins.php — « Mes bulletins de paie » (libre-service,
// lecture seule) : historique des salaires + affichage / téléchargement des
// bulletins PDF. Ouvert à TOUT le personnel payé de l'école, directeur
// compris (01/10/2026). Contenu partagé avec le secondaire :
// pages/paie/_mes_bulletins_contenu.php.
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion.php';
require_once __DIR__ . '/../../fonctions.php';
require_once __DIR__ . '/../../paie_fonctions.php';
exiger_role(['DIRECTEUR', 'ENSEIGNANT', 'SECRETAIRE', 'COMPTABLE']);

$url_vue  = APP_URL . '/pages/paie/bulletin.php';
$url_pdf  = APP_URL . '/pdf/bulletin_paie.php';
$url_page = APP_URL . '/pages/paie/mes_bulletins.php';
require __DIR__ . '/_mes_bulletins_contenu.php';
