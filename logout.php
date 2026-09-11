<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/connexion.php';   // annuaire + ecole_contexte (journal d'audit)
require_once __DIR__ . '/fonctions.php';
session_init();

if (!empty($_SESSION['user']['id']) || !empty($_SESSION['membre']['id'])) {
    require_once __DIR__ . '/bd/lib/audit.php';
    audit_log('deconnexion');
}

session_destroy();
header('Location: ' . APP_URL . '/login.php');
exit;
