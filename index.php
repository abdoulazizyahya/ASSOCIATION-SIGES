<?php
// ─────────────────────────────────────────────────────────────────────
//  index.php — point d'entrée.
//  • bare "/" et visiteur non connecté  → page d'accueil publique (accueil.php)
//  • "/?login" (bouton « Se connecter ») → login.php
//  • visiteur déjà connecté              → dashboard.php
//
//  On sert la vitrine ICI (et non via « DirectoryIndex accueil.php » dans
//  .htaccess) parce que certains hébergements mutualisés interdisent la
//  directive DirectoryIndex en .htaccess (AllowOverride restreint) — ce
//  qui provoquait un « Internal Server Error / DirectoryIndex not allowed ».
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/fonctions.php';
session_init();

$aller_login = isset($_GET['login']) || isset($_GET['connexion']) || isset($_GET['app']);

if (est_connecte()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}
if ($aller_login) {
    header('Location: ' . APP_URL . '/login.php');
    exit;
}

// Visiteur non connecté sur la racine → vitrine publique.
require __DIR__ . '/accueil.php';
