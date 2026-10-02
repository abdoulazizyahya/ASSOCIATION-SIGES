<?php
// secondaire/pages/paie/mes_bulletins.php — « Mes bulletins de paie »
// (libre-service, lecture seule) : historique des salaires + affichage /
// téléchargement des bulletins PDF. Ouvert à TOUT le personnel payé de
// l'école (01/10/2026). Contenu partagé avec le primaire :
// pages/paie/_mes_bulletins_contenu.php (mêmes tables de paie).
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../connexion.php';
require_once __DIR__ . '/../../../fonctions.php';
require_once __DIR__ . '/../../../paie_fonctions.php';
exiger_role(['ADMIN', 'PROVISEUR', 'CENSEUR', 'SG', 'INTENDANT', 'SECRETAIRE', 'ENSEIGNANT']);

$url_vue  = APP_URL . '/secondaire/pages/paie/bulletin.php';
$url_pdf  = APP_URL . '/secondaire/pdf/bulletin_paie.php';
$url_page = APP_URL . '/secondaire/pages/paie/mes_bulletins.php';
require __DIR__ . '/../../../pages/paie/_mes_bulletins_contenu.php';
