<?php
// association/logout.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
ecole_session_demarrer();
if (!empty($_SESSION['membre']['id'])) {
    require_once __DIR__ . '/../bd/lib/audit.php';
    audit_log('deconnexion');
}
$_SESSION = [];
session_destroy();
header('Location: ' . APP_URL . '/association/login.php');
