<?php
// Table de deploy/outils/reorganiser_arborescence.php --table=… :
// racine réduite à index.php (12/10/2026, demande utilisateur). Les
// anciennes adresses restent valides par .htaccess — en particulier les
// verif_*.php des QR déjà imprimés.
return [
    'correspondance' => [
        'config.php'                      => 'config/config.php',
        'config.local.php.example'        => 'config/config.local.php.example',
        'login.php'                       => 'compte/login.php',
        'logout.php'                      => 'compte/logout.php',
        'mot_de_passe_oublie.php'         => 'compte/mot_de_passe_oublie.php',
        'configurer_securite.php'         => 'compte/configurer_securite.php',
        'questions_securite_reporter.php' => 'compte/questions_securite_reporter.php',
        'profil.php'                      => 'compte/profil.php',
        'dashboard.php'                   => 'noyau/dashboard.php',
        'install.php'                     => 'installation/install.php',
        'verif_attestation.php'           => 'verif/attestation.php',
        'verif_bulletin.php'              => 'verif/bulletin.php',
        'verif_bulletin_hors_ligne.html'  => 'verif/bulletin_hors_ligne.html',
        'verif_carte.php'                 => 'verif/carte.php',
        'verif_honneur.php'               => 'verif/honneur.php',
        'verif_paiement.php'              => 'verif/paiement.php',
        'verif_recu.php'                  => 'verif/recu.php',
        'verif_scolarite.php'             => 'verif/scolarite.php',
    ],
    'prefixes_url' => [],
    // Réécrits seulement en position d'URL (après APP_URL, dans rediriger()) ;
    // le reste est repris à la main (listes de noms, basename()…).
    'fichiers_racine' => [
        'login.php', 'logout.php', 'mot_de_passe_oublie.php', 'configurer_securite.php',
        'questions_securite_reporter.php', 'profil.php', 'dashboard.php', 'install.php',
        'verif_attestation.php', 'verif_bulletin.php', 'verif_bulletin_hors_ligne.html', 'verif_carte.php',
        'verif_honneur.php', 'verif_paiement.php', 'verif_recu.php', 'verif_scolarite.php',
    ],
];
