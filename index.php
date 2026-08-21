<?php
// Point d'entrée — redirige vers login ou dashboard
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fonctions.php';
session_init();
if (est_connecte()) {
    header('Location: ' . APP_URL . '/dashboard.php');
} else {
    header('Location: ' . APP_URL . '/login.php');
}
exit;
