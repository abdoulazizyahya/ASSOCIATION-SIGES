<?php
// =====================================================================
//  bd/lib/paquet_installation.php — zip « SIGES vierge » (06/11/2026)
//
//  Construit, depuis les fichiers du site, un zip prêt à installer sur un
//  autre ordinateur (WampServer) : tout le code + vendor/, SANS aucune
//  donnée d'école ni configuration propre à ce serveur — mêmes exclusions
//  que .gitignore (config.local.php, logos/photos/dossiers d'élèves,
//  sauvegardes, archives de déploiement) + tests/ et les APK déposées.
//  Téléchargé depuis le portail association → Réglages.
// =====================================================================

// Chemin relatif (séparateur « / ») exclu du paquet ?
function paquet_exclu(string $rel, bool $dossier): bool {
    $nom = basename($rel);
    if (str_starts_with($nom, '~$')) return true;                          // verrous Office
    if ($dossier) {
        return in_array($rel, ['.git', '.claude', '.idea', '.vscode', 'tests', 'deploy/archives', 'bd/sauvegardes', 'assets/app'], true)
            || preg_match('#^assets/uploads/(etab|eleves/.+|dossiers_eleves|photos_depot/.+|profils|qr_\w+)$#', $rel);
    }
    if (in_array($rel, ['config/config.local.php', 'config.local.php', '.htaccess', 'promeducamsiges.html', 'docs/promeducamsiges.html'], true)) return true;
    if (preg_match('#^bd/backup_.*\.sql$#', $rel)) return true;
    if (preg_match('#\.(zip|rar|7z|gz|bak|log|tmp)$#i', $rel)) return true;
    // Uploads : seuls les .htaccess de protection et les logos d'opérateurs de paiement.
    if (str_starts_with($rel, 'assets/uploads/')) {
        return $nom !== '.htaccess' && !str_starts_with($rel, 'assets/uploads/operateurs/');
    }
    return false;
}

function paquet_lisezmoi(): string {
    return <<<TXT
SIGES — Système intégré de gestion scolaire
INSTALLATION SUR UN ORDINATEUR (WampServer)
==========================================

1. WampServer installé et démarré (icône verte).
   - PHP 8.1 ou plus récent : icône Wamp > PHP > Version.
   - Extensions PHP à cocher (icône Wamp > PHP > Extensions PHP) :
     mysqli, gd, zip, mbstring, openssl, fileinfo.
   - Modules Apache à cocher (icône Wamp > Apache > Modules Apache) :
     rewrite_module, headers_module.
   Wamp redémarre tout seul après chaque case cochée.

2. Copier le dossier « SIGES » de ce zip dans  C:\\wamp64\\www\\
   (on obtient C:\\wamp64\\www\\SIGES).

3. Ouvrir dans le navigateur :
       http://localhost/SIGES/installation/install.php
   La page vérifie d'abord PHP, les extensions et les modules, puis
   demande :
   - « Une seule école » (primaire ou secondaire), ou
   - « Plusieurs écoles » (association avec portail).
   Elle crée les bases de données, l'école, le compte administrateur et
   la configuration, puis ouvre la page de connexion.

4. Autres ordinateurs de l'école : rien à installer. Ouvrir
       http://<adresse IP de ce poste>/SIGES
   (icône Wamp > « Passer en ligne », et autoriser Apache dans le
   pare-feu Windows).

Développé par Ing ABDOUL-AZIZ Yahya
621400800 / 699758612 / 675744062 — abdoulazizyahya@gmail.com
TXT;
}

/**
 * Écrit le zip dans $dest. Tout est rangé sous « SIGES/ ».
 * Retourne ['fichiers' => n, 'octets' => taille du zip].
 */
function paquet_installation_construire(string $dest): array {
    $racine = realpath(__DIR__ . '/../..');
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Création du zip impossible.');
    $n = 0;
    $parcourir = function (string $dir, string $rel) use (&$parcourir, $zip, &$n) {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $chemin = $dir . DIRECTORY_SEPARATOR . $e;
            $r = $rel === '' ? $e : $rel . '/' . $e;
            if (is_link($chemin)) continue;
            if (is_dir($chemin)) {
                if (!paquet_exclu($r, true)) $parcourir($chemin, $r);
            } elseif (!paquet_exclu($r, false)) {
                $zip->addFile($chemin, 'SIGES/' . $r);
                $n++;
            }
        }
    };
    $parcourir($racine, '');
    $zip->addFromString('SIGES/LISEZMOI_INSTALLATION.txt', str_replace("\n", "\r\n", paquet_lisezmoi()));
    $zip->close();
    return ['fichiers' => $n, 'octets' => (int) filesize($dest)];
}
