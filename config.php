<?php
// ── Configuration de l'application ───────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'jaynitaare_v2_bd');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NOM',    'Jaynitaare · Gestion Scolaire');
define('APP_URL',    '/jaynitaare_v2');       // chemin depuis la racine web
// URL publique complète (schéma+hôte) pour les QR codes de vérification
// (bulletins/reçus/attestations/relevés) — à définir seulement si elle
// diffère de celle de la requête en cours (ex. reverse proxy, nom de
// domaine réel en production) :
// define('BULLETIN_VERIF_BASE_URL', 'https://mondomaine.cm/jaynitaare_v2');
//
// Usage local (WAMP) : quand l'application est ouverte via "localhost" (le
// cas normal depuis le poste serveur), hote_verif_reseau() (fonctions.php)
// remplace automatiquement "localhost" par l'IP réseau (LAN) détectée de la
// machine dans les QR codes — sans cela, un téléphone qui scanne le QR
// essaie de se connecter à LUI-MÊME (échec / plantage à l'ouverture du
// document vérifié). Si la détection automatique se trompe (plusieurs
// cartes réseau, VPN actif...), fixez-la explicitement — MAIS uniquement si
// le serveur a une IP LAN FIXE. Si l'IP change souvent (WiFi variés, partage
// de connexion téléphone), LAISSER CETTE LIGNE COMMENTÉE : la détection
// automatique (detecter_ip_lan() dans fonctions.php) suit alors le réseau
// courant, et les QR se régénèrent tout seuls au changement d'adresse.
// define('SERVEUR_LAN_HOST', '192.168.1.50');
define('UPLOAD_DIR', __DIR__ . '/assets/uploads/eleves/');
define('UPLOAD_URL', '/jaynitaare_v2/assets/uploads/eleves/');

// Clé privée ECDSA P-256 pour la signature offline des QR de bulletins
// (verif_bulletin_hors_ligne.html vérifie avec la clé publique correspondante,
// embarquée dans ce fichier — voir pdf/verif_lib.php::bulletin_verif_signature_offline()).
// Générée une fois via bd/generer_cles_verif_offline.php.
define('BULLETIN_VERIF_PRIVATE_KEY_PEM', <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgq3rkfV2Imq+iUz7t
PL6/TLUdbOQQ2ECzWl+OetBWZPWhRANCAASE8LkKsdrhieCdfy34Js0ZxQwBMgYn
UOxQ1vu7uT/Th5/VEOfLSrbs8Lx8mPRwl2lIITMClPOKm4NEfbO/0gnk
-----END PRIVATE KEY-----
PEM
);
