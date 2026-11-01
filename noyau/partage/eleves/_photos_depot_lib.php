<?php
// noyau/partage/eleves/_photos_depot_lib.php — Dossier de dépôt des photos,
// un par école (01/11/2026), primaire comme secondaire.
// assets/uploads/photos_depot/<CODE ÉCOLE>/ : on y copie des photos à la main
// (clé USB, FTP, gestionnaire de fichiers de l'hébergeur), chacune nommée par
// le NIU de l'élève ou son matricule. Les ZIP de photos_export.php peuvent y
// être décompressés tels quels, sous-dossiers de classe compris.
// Le bouton « Récupérer les photos du dossier » (photos_depot.php) les associe
// aux élèves, les recadre au format habituel (300×400 JPEG), les enregistre EN
// BASE puis les range dans traitees/<date>/. Les fichiers non reconnus restent
// en place. Dossier jamais servi par le web (.htaccess « Require all denied »,
// sans « Options » : refusé par Camoo).
require_once __DIR__ . '/_photos_lib.php';

const PHOTOS_DEPOT_TRAITEES = 'traitees';

/** Code de l'école courante, utilisable comme nom de dossier. */
function photos_depot_code(): string {
    $e = function_exists('ecole_courante') ? ecole_courante() : null;
    $c = strtoupper((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($e['code'] ?? '')));
    return $c !== '' ? $c : 'ECOLE';
}

/** Chemin absolu du dossier de dépôt de l'école (avec / final), créé et protégé si besoin. */
function photos_depot_dossier(): string {
    $racine = dirname(rtrim(UPLOAD_DIR, '/\\')) . '/photos_depot/';
    $dir = $racine . photos_depot_code() . '/';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_file($racine . '.htaccess')) {
        @file_put_contents($racine . '.htaccess',
            "# Dépôt manuel des photos d'élèves : jamais servi par le web (voir noyau/partage/eleves/_photos_depot_lib.php).\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Chemin à indiquer à l'utilisateur (relatif à la racine de l'application). */
function photos_depot_libelle(): string {
    return 'assets/uploads/photos_depot/' . photos_depot_code() . '/';
}

/** Images en attente (sous-dossiers compris, sauf traitees/) : chemins relatifs au dossier. */
function photos_depot_fichiers(int $max = 5000): array {
    $dir = photos_depot_dossier();
    $res = [];
    $pile = [''];
    while ($pile && count($res) < $max) {
        $rel = array_pop($pile);
        foreach (@scandir($dir . $rel) ?: [] as $n) {
            if ($n === '.' || $n === '..' || $n[0] === '.') continue;
            if (is_dir($dir . $rel . $n)) {
                if ($rel === '' && strcasecmp($n, PHOTOS_DEPOT_TRAITEES) === 0) continue;
                $pile[] = $rel . $n . '/';
            } elseif (preg_match('/\.(jpe?g|png|webp)$/i', $n)) {
                $res[] = $rel . $n;
            }
        }
    }
    sort($res, SORT_NATURAL | SORT_FLAG_CASE);
    return $res;
}

/** Clé de comparaison NIU / matricule / nom de fichier (comme PhotosEleves.normaliser côté navigateur). */
function photos_cle(string $s): string {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($t !== false ? $t : $s));
}

/**
 * Index des élèves actifs de l'école : ['niu' => [clé => id], 'mat' => [clé => id],
 * 'eleves' => [id => [nom, photo]]] ; id -1 = clé partagée par plusieurs élèves (ambiguë).
 */
function photos_depot_index(): array {
    $sec  = photos_est_secondaire();
    $rows = $sec
        ? db_all("SELECT id, niu, matricule AS mat, nom, prenom, photo FROM eleve WHERE statut='actif'")
        : db_all("SELECT id_eleve AS id, niu, Mat_elv AS mat, Nom_elv AS nom, Prenom_elv AS prenom, (Photo_elv IS NOT NULL) AS photo FROM eleve WHERE statut='actif'");
    $ix = ['niu' => [], 'mat' => [], 'eleves' => []];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $ix['eleves'][$id] = [
            'nom'   => trim(mb_strtoupper((string) $r['nom']) . ' ' . ($r['prenom'] ?? '')),
            'photo' => $sec ? photo_sec_presente($r['photo'] ?? null) : (bool) $r['photo'],
        ];
        foreach (['niu', 'mat'] as $k) {
            $c = photos_cle((string) ($r[$k] ?? ''));
            if (strlen($c) < 3) continue;
            $ix[$k][$c] = isset($ix[$k][$c]) && $ix[$k][$c] !== $id ? -1 : $id;
        }
    }
    return $ix;
}

/** Élève correspondant à un nom de fichier : [id, 'NIU'|'matricule'] ; id 0 = inconnu, -1 = ambigu. */
function photos_depot_associer(string $fichier, array $ix): array {
    $base = pathinfo($fichier, PATHINFO_FILENAME);
    // « <NIU>_<id> » : nom donné par l'export ZIP à un NIU/matricule en double.
    foreach (array_unique([$base, (string) preg_replace('/_\d+$/', '', $base)]) as $b) {
        $c = photos_cle($b);
        if (isset($ix['niu'][$c])) return [$ix['niu'][$c], 'NIU'];
        if (isset($ix['mat'][$c])) return [$ix['mat'][$c], 'matricule'];
    }
    return [0, ''];
}

/**
 * Photo d'appareil → JPEG 300×400, même cadrage automatique que
 * PhotosEleves.recadrerAuto (rapport 3/4, centré, un peu vers le haut),
 * orientation EXIF des téléphones appliquée. null si illisible.
 */
function photos_depot_preparer(string $chemin): ?string {
    $info = @getimagesize($chemin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)
        || $info[0] < 50 || $info[1] < 50 || $info[0] * $info[1] > 40000000) return null;
    $src = @imagecreatefromstring((string) file_get_contents($chemin));
    if (!$src) return null;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($chemin)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) { $r = imagerotate($src, $angle, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $sw = imagesx($src); $sh = imagesy($src);
    $cw = $sw; $ch = $sw * 4 / 3;
    if ($ch > $sh) { $ch = $sh; $cw = $sh * 3 / 4; }
    $cx = ($sw - $cw) / 2; $cy = max(0, ($sh - $ch) * 0.35);
    $dst = imagecreatetruecolor(300, 400);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, (int) $cx, (int) $cy, 300, 400, (int) $cw, (int) $ch);
    imagedestroy($src);
    ob_start(); imagejpeg($dst, null, 88); $jpg = (string) ob_get_clean();
    imagedestroy($dst);
    return $jpg !== '' ? $jpg : null;
}

/** Range un fichier traité dans traitees/<date>/ (sous-dossier d'origine conservé). */
function photos_depot_ranger(string $rel): void {
    $dir  = photos_depot_dossier();
    $dest = $dir . PHOTOS_DEPOT_TRAITEES . '/' . date('Y-m-d') . '/' . $rel;
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    if (is_file($dest)) $dest = (string) preg_replace('/(\.[^.\/]+)$/', '_' . date('His') . '$1', $dest);
    if (!@rename($dir . $rel, $dest)) @unlink($dir . $rel);
}

/**
 * Traite le dossier de dépôt : au plus $max photos enregistrées par appel
 * (le navigateur relance tant qu'il en reste). Retour :
 * ['lignes' => [[fichier, etat, id, eleve, par, url?]], 'enregistrees' => n, 'reste' => n]
 * etat : ok | existe (déjà une photo, remplacement non demandé) | inconnu |
 *        ambigu (NIU/matricule partagé) | doublon (2 fichiers pour un élève) | illisible | erreur.
 */
function photos_depot_traiter(bool $remplacer, int $max = 100): array {
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);
    $dir = photos_depot_dossier();
    $ix  = photos_depot_index();
    $lignes = []; $faits = 0; $reste = 0; $vus = [];
    foreach (photos_depot_fichiers() as $rel) {
        [$id, $par] = photos_depot_associer($rel, $ix);
        $el = $id > 0 ? ($ix['eleves'][$id] ?? null) : null;
        $l = ['fichier' => $rel, 'id' => max(0, $id), 'eleve' => $el['nom'] ?? '', 'par' => $par];
        if ($id === 0)                        $l['etat'] = 'inconnu';
        elseif ($id < 0)                      $l['etat'] = 'ambigu';
        elseif (isset($vus[$id]))             $l['etat'] = 'doublon';
        elseif ($el['photo'] && !$remplacer)  $l['etat'] = 'existe';
        elseif ($faits >= $max)               { $reste++; continue; }
        else {
            $faits++;
            $vus[$id] = true;
            $jpg = photos_depot_preparer($dir . $rel);
            if ($jpg === null) $l['etat'] = 'illisible';
            else {
                try {
                    $l['url']  = photo_eleve_enregistrer($id, $jpg, IMAGETYPE_JPEG);
                    $l['etat'] = 'ok';
                    photos_depot_ranger($rel);
                } catch (Throwable $e) {
                    $l['etat'] = 'erreur';
                    $l['message'] = $e->getMessage();
                }
            }
        }
        $lignes[] = $l;
    }
    return ['lignes' => $lignes, 'enregistrees' => count(array_filter($lignes, fn($l) => $l['etat'] === 'ok')), 'reste' => $reste];
}
