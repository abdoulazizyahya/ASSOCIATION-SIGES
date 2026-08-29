<?php
// association/sortir_ecole.php — fin de visite, retour au portail
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
ecole_session_demarrer();

unset(
    $_SESSION['visite_asso'],
    $_SESSION['visite_asso_ecriture'],
    $_SESSION['ecole'],
    $_SESSION['user'],
    $_SESSION['user_id']
);
header('Location: ' . APP_URL . '/association/index.php');
