<?php
// parents/deconnexion.php — ferme la session parent (l'école choisie reste mémorisée).
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
session_init();
unset($_SESSION['parent'], $_SESSION['ep_tel']);
session_regenerate_id(true);
header('Location: ' . APP_URL . '/parents/index.php');
exit;
