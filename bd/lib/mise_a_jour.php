<?php
// =====================================================================
//  bd/lib/mise_a_jour.php — règles et vérification des zips de mise à jour
//  (25/10/2026). Lecture seule : rien n'est écrit sur le serveur ici.
//  L'installation depuis l'espace association (étapes 3 et 4 ci-dessous)
//  n'est pas encore en place : elle attend la décision du propriétaire.
// =====================================================================
//  Le propriétaire envoie le zip produit par deploy/outils/construire_zip.php :
//   1. contrôle : chemins sûrs (ni « .. » ni chemin absolu), dossiers et
//      extensions autorisés, jamais config/config.local.php ni .htaccess ;
//      empreintes SHA-256 du manifeste siges_maj.json vérifiées une à une ;
//   2. aperçu : fichiers nouveaux / modifiés / identiques ;
//   3. installation : copie des fichiers actuels qui vont être remplacés
//      dans bd/sauvegardes/avant_maj_<horo>.zip (retour arrière possible),
//      écriture fichier par fichier (temporaire puis renommage), puis
//      migrations de toutes les écoles actives (assoc_migrer_ecole) ;
//   4. annulation : remet les fichiers de la sauvegarde et retire ceux
//      que la mise à jour avait ajoutés (les migrations, elles, restent :
//      elles sont idempotentes et sauvegardées avant chaque exécution).
// =====================================================================

const MAJ_MANIFESTE = 'siges_maj.json';
const MAJ_RACINES   = ['assets', 'association', 'bd', 'compte', 'config', 'docs', 'installation', 'noyau', 'primaire', 'secondaire', 'verif', 'vendor'];
const MAJ_FICHIERS_RACINE = ['index.php', 'composer.json', 'composer.lock', 'README.md'];
const MAJ_EXTENSIONS = ['php', 'sql', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf',
                        'html', 'txt', 'md', 'xml', 'map', 'z', 'afm', 'pfb', 'inc'];
const MAJ_INTERDITS  = ['config/config.local.php'];

function maj_racine(): string { return realpath(__DIR__ . '/../..'); }
function maj_dossier(): string {
    $d = __DIR__ . '/../sauvegardes';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

/** Motif d'un chemin refusé, ou null s'il est acceptable. */
function maj_chemin_refuse(string $p): ?string {
    if ($p === '' || str_contains($p, '\\') || str_contains($p, "\0")) return 'caractères interdits';
    if ($p[0] === '/' || preg_match('~^[A-Za-z]:~', $p)) return 'chemin absolu';
    $parts = explode('/', $p);
    foreach ($parts as $x) if ($x === '..' || $x === '.' || $x === '') return 'chemin invalide';
    if (in_array($p, MAJ_INTERDITS, true)) return 'fichier de configuration du serveur (jamais remplacé)';
    if (str_starts_with($parts[count($parts) - 1], '.')) return 'fichier caché (.htaccess, .env…)';
    if (str_starts_with($p, 'bd/sauvegardes/')) return 'dossier des sauvegardes';
    if (count($parts) === 1) return in_array($p, MAJ_FICHIERS_RACINE, true) ? null : 'fichier racine non prévu';
    if (!in_array($parts[0], MAJ_RACINES, true)) return 'dossier non prévu (' . $parts[0] . ')';
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    if (!in_array($ext, MAJ_EXTENSIONS, true)) return "extension .$ext non autorisée";
    return null;
}

/**
 * Analyse un zip. Retour : ['ok', 'erreurs' => [], 'fichiers' => [chemin => etat nouveau|modifie|identique],
 * 'manifeste' => ?array, 'verifie' => bool].
 */
function maj_analyser(string $zip_chemin): array {
    $r = ['ok' => false, 'erreurs' => [], 'fichiers' => [], 'manifeste' => null, 'verifie' => false];
    $z = new ZipArchive();
    if ($z->open($zip_chemin) !== true) { $r['erreurs'][] = "Ce fichier n'est pas un zip lisible."; return $r; }
    $man = $z->getFromName(MAJ_MANIFESTE);
    if ($man !== false) {
        $r['manifeste'] = json_decode($man, true);
        if (!is_array($r['manifeste']) || !is_array($r['manifeste']['fichiers'] ?? null)) { $r['erreurs'][] = 'Manifeste ' . MAJ_MANIFESTE . ' illisible.'; $r['manifeste'] = null; }
    }
    $racine = maj_racine();
    $vus = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $nom = $z->getNameIndex($i);
        if ($nom === MAJ_MANIFESTE || str_ends_with($nom, '/')) continue;
        if ($motif = maj_chemin_refuse($nom)) { $r['erreurs'][] = "$nom : $motif."; continue; }
        $contenu = $z->getFromIndex($i);
        if ($contenu === false) { $r['erreurs'][] = "$nom : illisible dans le zip."; continue; }
        $h = hash('sha256', $contenu);
        if ($r['manifeste']) {
            $attendu = $r['manifeste']['fichiers'][$nom] ?? null;
            if ($attendu === null) { $r['erreurs'][] = "$nom : absent du manifeste."; continue; }
            if (!hash_equals($attendu, $h)) { $r['erreurs'][] = "$nom : empreinte différente du manifeste (fichier altéré)."; continue; }
        }
        $actuel = $racine . '/' . $nom;
        $r['fichiers'][$nom] = !is_file($actuel) ? 'nouveau' : (hash_file('sha256', $actuel) === $h ? 'identique' : 'modifie');
        $vus[$nom] = true;
    }
    if ($r['manifeste']) foreach (array_keys($r['manifeste']['fichiers']) as $nom)
        if (!isset($vus[$nom]) && !maj_chemin_refuse($nom)) $r['erreurs'][] = "$nom : annoncé par le manifeste mais absent du zip.";
    $z->close();
    if (!$r['fichiers'] && !$r['erreurs']) $r['erreurs'][] = 'Aucun fichier applicable dans ce zip.';
    $r['verifie'] = $r['manifeste'] !== null;
    $r['ok'] = !$r['erreurs'];
    ksort($r['fichiers']);
    return $r;
}
