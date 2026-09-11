<?php
// ── Configuration de l'application ───────────────────────────
//
//  Surcharge par environnement : si un fichier config.local.php existe à
//  côté de celui-ci, il est chargé EN PREMIER et ses define() gagnent
//  (chaque constante ci-dessous est posée seulement si elle n'est pas
//  déjà définie). En LAN / WAMP, config.local.php est absent → les
//  valeurs par défaut ci-dessous s'appliquent, comportement historique
//  inchangé. En production (Camoo, cPanel), déposer un config.local.php
//  d'après config.local.php.example. config.local.php est gitignoré.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'promeducam_jaynitaare');   // repli « école n°1 » si l'annuaire association est absent
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');

// ── Multi-établissement ──────────────────────────────────────
// Base centrale « annuaire » de l'association (écoles, NIU, personnel,
// comptes membres). Optionnelle : tant que bd/assoc/installer.php n'a pas
// tourné, l'application reste mono-école sur DB_NAME. Voir connexion_assoc.php.
defined('DB_NAME_ASSOC') || define('DB_NAME_ASSOC', 'promeducam_assoc');
// Domaine racine de l'association pour la résolution par sous-domaine en
// production (ecole1.assoc.cm). Vide en LAN/WAMP : la résolution se fait
// alors par session (+ ?ec= pour les pages publiques).
defined('ASSOC_DOMAINE') || define('ASSOC_DOMAINE', '');
// Hôte canonique unique de l'application (ex. « promeducam.beero.cm »).
// Quand la requête arrive sur cet hôte, la résolution par sous-domaine est
// court-circuitée (ecole_contexte.php::_sous_domaine_requete) : l'école est
// choisie au login (session) + ?ec=CODE pour les pages publiques. Vide en LAN.
defined('APP_HOTE') || define('APP_HOTE', '');
// Création d'une école : true = consommer une base vide d'un pool pré-créé
// (hébergement mutualisé sans droit CREATE DATABASE — voir bd/assoc/pool_enregistrer.php
// et connexion_assoc.php::creer_etablissement). false/absent = CREATE DATABASE
// direct (LAN / serveur dédié).
defined('ECOLE_POOL_ACTIF') || define('ECOLE_POOL_ACTIF', false);

defined('APP_NOM') || define('APP_NOM', 'SIGES · Gestion scolaire');

// ── Journal d'audit (bd/lib/audit.php) ──────────────────────────────
// AUDIT_GEOIP : localisation approximative des connexions à partir de
//   l'IP, via le service gratuit ip-api.com (l'IP du visiteur est donc
//   envoyée à ce tiers). Résultat mis en cache par IP (table geo_ip_cache).
//   Mettre false dans config.local.php pour ne conserver que l'IP.
// AUDIT_RETENTION_MOIS : ancienneté au-delà de laquelle la purge manuelle
//   (bouton dans association/journal.php) retire les entrées.
defined('AUDIT_GEOIP')          || define('AUDIT_GEOIP', true);
defined('AUDIT_RETENTION_MOIS') || define('AUDIT_RETENTION_MOIS', 12);

// ── Chemin de l'application depuis la racine web (APP_URL) ───────────
// Déduit AUTOMATIQUEMENT de l'emplacement du dossier : si vous renommez
// le dossier (ex. jaynitaare_v2 → SIGES), la navigation suit le nouveau
// nom sans rien changer ici. Le calcul compare le dossier de ce fichier
// à la racine web (DOCUMENT_ROOT) ; en CLI (scripts de migration) on se
// rabat sur le nom du dossier. Pour forcer une valeur (reverse proxy,
// app à la racine du domaine → ''), la définir dans config.local.php.
if (!defined('APP_URL')) {
    $__app_url = null;
    $__dir = str_replace('\\', '/', __DIR__);
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $__docroot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'], '/\\'));
        $__real_docroot = realpath($__docroot);
        if ($__real_docroot !== false) {
            $__real_docroot = str_replace('\\', '/', $__real_docroot);
            $__real_dir = str_replace('\\', '/', realpath(__DIR__));
            if ($__real_dir !== '' && strpos($__real_dir . '/', $__real_docroot . '/') === 0) {
                $__app_url = rtrim(substr($__real_dir, strlen($__real_docroot)), '/');
            }
        }
        if ($__app_url === null && strpos($__dir . '/', $__docroot . '/') === 0) {
            $__app_url = rtrim(substr($__dir, strlen($__docroot)), '/');
        }
    }
    if ($__app_url === null) {
        // CLI ou racine web introuvable : nom du dossier courant.
        $__app_url = '/' . basename(__DIR__);
    }
    define('APP_URL', $__app_url);
    unset($__app_url, $__dir, $__docroot, $__real_docroot, $__real_dir);
}
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
defined('UPLOAD_DIR') || define('UPLOAD_DIR', __DIR__ . '/assets/uploads/eleves/');
defined('UPLOAD_URL') || define('UPLOAD_URL', APP_URL . '/assets/uploads/eleves/');

// Clé privée ECDSA P-256 pour la signature offline des QR de bulletins
// (verif_bulletin_hors_ligne.html vérifie avec la clé publique correspondante,
// embarquée dans ce fichier — voir pdf/verif_lib.php::bulletin_verif_signature_offline()).
// Générée une fois via bd/generer_cles_verif_offline.php.
// ⚠ Garder la MÊME clé sur tous les environnements (LAN + prod) : en changer
// invaliderait la vérification hors-ligne des bulletins déjà imprimés.
defined('BULLETIN_VERIF_PRIVATE_KEY_PEM') || define('BULLETIN_VERIF_PRIVATE_KEY_PEM', <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgq3rkfV2Imq+iUz7t
PL6/TLUdbOQQ2ECzWl+OetBWZPWhRANCAASE8LkKsdrhieCdfy34Js0ZxQwBMgYn
UOxQ1vu7uT/Th5/VEOfLSrbs8Lx8mPRwl2lIITMClPOKm4NEfbO/0gnk
-----END PRIVATE KEY-----
PEM
);
