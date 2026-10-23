<?php
// ─────────────────────────────────────────────────────────────────────
//  index.php — point d'entrée.
//  • bare "/" et visiteur non connecté  → page d'accueil publique (accueil.php),
//    OU directement la page de connexion si l'association l'a choisi
//    (portail association → Réglages, page_entree_site(), 18/10/2026)
//  • "/?login" (bouton « Se connecter ») → compte/login.php
//  • "/?accueil"                         → vitrine, quel que soit le réglage
//  • visiteur déjà connecté              → noyau/dashboard.php
//
//  On sert la vitrine ICI (et non via « DirectoryIndex accueil.php » dans
//  .htaccess) parce que certains hébergements mutualisés interdisent la
//  directive DirectoryIndex en .htaccess (AllowOverride restreint) — ce
//  qui provoquait un « Internal Server Error / DirectoryIndex not allowed ».
// ─────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/noyau/fonctions.php';
require_once __DIR__ . '/noyau/connexion_assoc.php';   // assoc_param() : réglage de la page d'entrée
session_init();

$aller_login = isset($_GET['login']) || isset($_GET['connexion']) || isset($_GET['app'])
    || (!isset($_GET['accueil']) && page_entree_site() === 'connexion');

if (est_connecte()) {
    header('Location: ' . APP_URL . '/noyau/dashboard.php');
    exit;
}
if ($aller_login) {
    header('Location: ' . APP_URL . '/compte/login.php');
    exit;
}

// Visiteur non connecté sur la racine → vitrine publique.
require __DIR__ . '/noyau/accueil.php';
