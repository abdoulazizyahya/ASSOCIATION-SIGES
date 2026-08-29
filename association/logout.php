<?php
// association/logout.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
ecole_session_demarrer();
journaliser_action('deconnexion_membre');
$_SESSION = [];
session_destroy();
header('Location: ' . APP_URL . '/association/login.php');
