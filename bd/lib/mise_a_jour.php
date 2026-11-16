<?php
// =====================================================================
//  bd/lib/mise_a_jour.php — règles et vérification des zips de mise à jour
//  (25/10/2026). Installation depuis Association → Mise à jour
//  (association/mise_a_jour.php), demandée par le propriétaire le 16/11/2026.
//  SIGNATURE OBLIGATOIRE : le manifeste est signé (RSA-SHA256) avec la clé
//  privée du propriétaire (deploy/cles/maj_privee.pem, jamais sur le
//  serveur) ; le serveur ne détient que la clé publique
//  (bd/lib/cle_maj_publique.pem). Un compte volé ne suffit donc pas pour
//  déposer du code : sans la clé privée, aucun zip ne s'installe.
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
const MAJ_SIGNATURE = 'siges_maj.sig';     // base64 de la signature RSA-SHA256 du manifeste (octets exacts)
const MAJ_RACINES   = ['assets', 'association', 'bd', 'compte', 'config', 'docs', 'installation', 'noyau', 'parents', 'primaire', 'secondaire', 'verif', 'vendor'];
const MAJ_FICHIERS_RACINE = ['index.php', 'composer.json', 'composer.lock', 'README.md'];
const MAJ_EXTENSIONS = ['php', 'sql', 'css', 'js', 'json', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'otf',
                        'html', 'txt', 'md', 'xml', 'map', 'z', 'afm', 'pfb', 'inc'];
const MAJ_INTERDITS  = ['config/config.local.php'];

function maj_cle_publique_chemin(): string { return __DIR__ . '/cle_maj_publique.pem'; }

/** Signature (base64) des octets du manifeste avec une clé privée PEM. */
function maj_signer(string $manifeste, string $cle_privee_pem): string {
    $k = openssl_pkey_get_private($cle_privee_pem);
    if (!$k || !openssl_sign($manifeste, $sig, $k, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signature impossible : clé privée invalide.');
    return base64_encode($sig);
}

/** true si la signature correspond au manifeste et à la clé publique du serveur. */
function maj_verifier_signature(string $manifeste, string $sig_b64, ?string $cle_publique_pem = null): bool {
    $pem = $cle_publique_pem ?? (is_file(maj_cle_publique_chemin()) ? (string) file_get_contents(maj_cle_publique_chemin()) : '');
    if ($pem === '' || ($k = openssl_pkey_get_public($pem)) === false) return false;
    $sig = base64_decode(trim($sig_b64), true);
    return $sig !== false && openssl_verify($manifeste, $sig, $k, OPENSSL_ALGO_SHA256) === 1;
}

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
    if (str_starts_with($p, 'bd/sauvegardes/')) return 'dossier des sauvegardes';    if (count($parts) === 1) return in_array($p, MAJ_FICHIERS_RACINE, true) ? null : 'fichier racine non prévu';
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
    $r = ['ok' => false, 'erreurs' => [], 'fichiers' => [], 'supprimes' => [], 'manifeste' => null, 'verifie' => false, 'signe' => false];
    $z = new ZipArchive();
    if ($z->open($zip_chemin) !== true) { $r['erreurs'][] = "Ce fichier n'est pas un zip lisible."; return $r; }
    $man = $z->getFromName(MAJ_MANIFESTE);
    if ($man !== false) {
        $r['manifeste'] = json_decode($man, true);
        if (!is_array($r['manifeste']) || !is_array($r['manifeste']['fichiers'] ?? null)) { $r['erreurs'][] = 'Manifeste ' . MAJ_MANIFESTE . ' illisible.'; $r['manifeste'] = null; }
        $sig = $z->getFromName(MAJ_SIGNATURE);
        $r['signe'] = $r['manifeste'] !== null && $sig !== false && maj_verifier_signature($man, $sig);
        // Fichiers à retirer du serveur (supprimés du code depuis le commit de départ).
        foreach ((array) ($r['manifeste']['supprimes'] ?? []) as $p) {
            if (!is_string($p) || maj_chemin_refuse($p) !== null) { $r['erreurs'][] = 'Suppression refusée : ' . (is_string($p) ? $p : '?') . '.'; continue; }
            if (is_file(maj_racine() . '/' . $p)) $r['supprimes'][] = $p;
        }
    }
    $racine = maj_racine();
    $vus = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $nom = $z->getNameIndex($i);
        if ($nom === MAJ_MANIFESTE || $nom === MAJ_SIGNATURE || str_ends_with($nom, '/')) continue;
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

// =====================================================================
//  Installation et retour arrière (16/11/2026)
// =====================================================================

/** Écrit un fichier sans jamais le laisser à moitié écrit (temporaire puis renommage). */
function _maj_ecrire(string $dest, string $contenu): bool {
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
    $tmp = $dest . '.maj_tmp';
    if (@file_put_contents($tmp, $contenu) !== strlen($contenu)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $dest)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate') && str_ends_with($dest, '.php')) @opcache_invalidate($dest, true);
    return true;
}

function _maj_retirer(string $chemin): bool {
    if (!is_file($chemin)) return true;
    $ok = @unlink($chemin);
    if ($ok && function_exists('opcache_invalidate') && str_ends_with($chemin, '.php')) @opcache_invalidate($chemin, true);
    return $ok;
}

function _maj_echec(string $m, ?string $sauv = null): array {
    return ['ok' => false, 'message' => $m, 'sauvegarde' => $sauv, 'ecrits' => 0, 'supprimes' => 0];
}

/**
 * Installe un zip SIGNÉ. Sauvegarde d'abord les fichiers remplacés ou supprimés
 * dans bd/sauvegardes/avant_maj_<horo>.zip ; au premier échec d'écriture, tout
 * est remis en l'état. Les migrations des écoles ne sont PAS lancées ici : elles
 * restent un clic explicite sur la page Migrations (choix du propriétaire).
 * Retour : ['ok', 'message', 'sauvegarde' => ?nom, 'ecrits' => n, 'supprimes' => n].
 */
function maj_installer(string $zip_chemin, string $auteur = ''): array {
    $an = maj_analyser($zip_chemin);
    if (!$an['ok'])    return _maj_echec('Zip refusé : ' . implode(' ', array_slice($an['erreurs'], 0, 5)));
    if (!$an['signe']) return _maj_echec("Zip refusé : signature absente ou invalide (seuls les zips construits avec la clé du propriétaire s'installent).");

    $racine    = maj_racine();
    $a_ecrire  = array_keys(array_filter($an['fichiers'], fn($e) => $e !== 'identique'));
    $nouveaux  = array_keys(array_filter($an['fichiers'], fn($e) => $e === 'nouveau'));
    $remplaces = array_values(array_diff($a_ecrire, $nouveaux));
    if (!$a_ecrire && !$an['supprimes'])
        return ['ok' => true, 'message' => 'Rien à installer : tous les fichiers sont déjà à jour.', 'sauvegarde' => null, 'ecrits' => 0, 'supprimes' => 0];

    // 1. Sauvegarde des fichiers actuels qui vont être remplacés ou retirés.
    @set_time_limit(300);
    $nom_sauv = 'avant_maj_' . date('Y-m-d_H\hi\ms') . '.zip';
    $sauv = maj_dossier() . '/' . $nom_sauv;
    $b = new ZipArchive();
    if ($b->open($sauv, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
        return _maj_echec("Impossible de créer la sauvegarde dans bd/sauvegardes/ (droits d'écriture ?). Rien n'a été modifié.");
    foreach (array_merge($remplaces, $an['supprimes']) as $p) $b->addFile($racine . '/' . $p, 'fichiers/' . $p);
    $b->addFromString('maj_info.json', json_encode([
        'date' => date('c'), 'auteur' => $auteur,
        'commit' => $an['manifeste']['commit'] ?? '', 'depuis' => $an['manifeste']['depuis'] ?? '',
        'nouveaux' => $nouveaux, 'remplaces' => $remplaces, 'supprimes' => $an['supprimes'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if (!$b->close()) { @unlink($sauv); return _maj_echec("Sauvegarde avant mise à jour impossible : rien n'a été modifié."); }

    // 2. Écriture fichier par fichier (empreinte revérifiée) ; au premier échec, retour arrière complet.
    $z = new ZipArchive(); $z->open($zip_chemin);
    $faits = 0; $retires = 0; $echec = null;
    foreach ($a_ecrire as $p) {
        $c = $z->getFromName($p);
        if ($c === false || !hash_equals((string) $an['manifeste']['fichiers'][$p], hash('sha256', $c)) || !_maj_ecrire($racine . '/' . $p, $c)) { $echec = $p; break; }
        $faits++;
    }
    $z->close();
    if ($echec === null) foreach ($an['supprimes'] as $p) {
        if (!_maj_retirer($racine . '/' . $p)) { $echec = $p; break; }
        $retires++;
    }
    if ($echec !== null) {
        $ra = maj_annuler($nom_sauv);
        return _maj_echec("Écriture impossible sur « $echec » (droits du dossier ?). "
            . ($ra['ok'] ? "Les fichiers ont été remis en l'état." : 'ATTENTION : ' . $ra['message']), $nom_sauv);
    }
    return ['ok' => true, 'message' => "$faits fichier(s) installé(s)" . ($retires ? ", $retires retiré(s)" : '') . '.',
            'sauvegarde' => $nom_sauv, 'ecrits' => $faits, 'supprimes' => $retires];
}

/** Mises à jour installées (sauvegardes avant_maj_* / annulee_maj_*), plus récentes d'abord. */
function maj_historique(): array {
    $l = [];
    foreach (array_merge(glob(maj_dossier() . '/avant_maj_*.zip') ?: [], glob(maj_dossier() . '/annulee_maj_*.zip') ?: []) as $f) {
        $z = new ZipArchive(); $info = null;
        if ($z->open($f) === true) { $info = json_decode((string) $z->getFromName('maj_info.json'), true); $z->close(); }
        if (!is_array($info)) continue;
        $l[] = ['nom' => basename($f), 'annulee' => str_starts_with(basename($f), 'annulee_'), 'info' => $info, 'mtime' => filemtime($f)];
    }
    usort($l, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $l;
}

/** Remet les fichiers d'avant une mise à jour et retire ceux qu'elle avait ajoutés. */
function maj_annuler(string $nom_sauv): array {
    if (!preg_match('/^avant_maj_[0-9A-Za-z_-]+\.zip$/', $nom_sauv)) return ['ok' => false, 'message' => 'Sauvegarde inconnue.'];
    $f = maj_dossier() . '/' . $nom_sauv;
    $z = new ZipArchive();
    if (!is_file($f) || $z->open($f) !== true) return ['ok' => false, 'message' => 'Sauvegarde introuvable.'];
    $info = json_decode((string) $z->getFromName('maj_info.json'), true);
    if (!is_array($info)) { $z->close(); return ['ok' => false, 'message' => 'Sauvegarde sans description (maj_info.json).']; }
    $racine = maj_racine(); $erreurs = [];
    foreach (array_merge((array) ($info['remplaces'] ?? []), (array) ($info['supprimes'] ?? [])) as $p) {
        if (!is_string($p) || maj_chemin_refuse($p) !== null) continue;
        $c = $z->getFromName('fichiers/' . $p);
        if ($c === false || !_maj_ecrire($racine . '/' . $p, $c)) $erreurs[] = $p;
    }
    foreach ((array) ($info['nouveaux'] ?? []) as $p)
        if (is_string($p) && maj_chemin_refuse($p) === null && !_maj_retirer($racine . '/' . $p)) $erreurs[] = $p;
    $z->close();
    if ($erreurs) return ['ok' => false, 'message' => 'Retour arrière incomplet, fichiers à vérifier : ' . implode(', ', array_slice($erreurs, 0, 10))];
    @rename($f, maj_dossier() . '/annulee_' . substr($nom_sauv, strlen('avant_')));
    return ['ok' => true, 'message' => "Mise à jour annulée : les fichiers d'avant ont été remis."];
}
