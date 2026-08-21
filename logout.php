<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fonctions.php';
session_init();
session_destroy();
header('Location: ' . APP_URL . '/login.php'); exit;
