<?php
// primaire/pages/paie/mes_bulletins.php — « Mes bulletins de paie » (libre-service,
// lecture seule) : historique des salaires + affichage / téléchargement des
// bulletins PDF. Ouvert à TOUT le personnel payé de l'école, directeur
// compris (01/10/2026). Contenu partagé avec le secondaire :
// primaire/pages/paie/_mes_bulletins_contenu.php.
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../noyau/connexion.php';
require_once __DIR__ . '/../../../noyau/fonctions.php';
require_once __DIR__ . '/../../../noyau/paie_fonctions.php';
exiger_role(['DIRECTEUR', 'ENSEIGNANT', 'SECRETAIRE', 'COMPTABLE']);

$url_vue  = APP_URL . '/primaire/pages/paie/bulletin.php';
$url_pdf  = APP_URL . '/primaire/pdf/bulletin_paie.php';
$url_page = APP_URL . '/primaire/pages/paie/mes_bulletins.php';
require __DIR__ . '/../../../noyau/partage/paie/_mes_bulletins_contenu.php';
