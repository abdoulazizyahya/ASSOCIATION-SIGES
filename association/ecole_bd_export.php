<?php
// association/ecole_bd_export.php — Export de la base d'UNE école.
// Superadmin association uniquement. Fichier téléchargé (un temporaire
// côté serveur, supprimé aussitôt).
//   ?id=N            → dump .sql
//   ?id=N&gzip=1      → dump .sql.gz
//   ?id=N&format=zip  → archive COMPLÈTE .zip : dump.sql.gz + manifest.json
//                       + assets/uploads/etab/<slug>/ (logos, signatures,
//                       pièces de dossier). Les photos d'élèves sont en
//                       BLOB dans la table `eleve` → déjà dans le dump.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';
require_once __DIR__ . '/../bd/lib/ecole_maintenance.php';
exiger_superadmin_association();

// Jeton CSRF exigé même en GET (lien signé depuis la fiche établissement).
csrf_verifier();

$id = (int) ($_GET['id'] ?? 0);
$e  = $id ? assoc_one("SELECT * FROM etablissement WHERE id=?", [$id]) : null;
if (!$e) {
    http_response_code(404);
    exit('Établissement introuvable.');
}

$db     = $e['db_name'];
$format = ($_GET['format'] ?? '') === 'zip' ? 'zip' : 'sql';
$gzip   = $format === 'sql' && !empty($_GET['gzip']) && function_exists('gzopen');

try {
    ecole_maint_garde_base($db);
} catch (\Throwable $ex) {
    http_response_code(400);
    exit('Export impossible : ' . $ex->getMessage());
}

$horo = date('Ymd_His');
$slug = strtolower(preg_replace('/[^a-z0-9]/i', '', $e['code']));

if ($format === 'zip') {
    $nom_fichier = $slug . '_' . $db . '_' . $horo . '.zip';
    $tmp = tempnam(sys_get_temp_dir(), 'siges_expzip_') . '.zip';
    $r = ecole_export_zip($id, $tmp);
    if (!$r['ok']) {
        @unlink($tmp);
        http_response_code(500);
        exit('Erreur pendant l\'export : ' . $r['message']);
    }
    journaliser_action('ecole_bd_export', $id,
        $e['code'] . ' — ' . $db . ' (.zip, ' . _siges_exp_taille((int) filesize($tmp))
        . ', ' . (int) $r['fichiers'] . ' fichiers)');
    $mime = 'application/zip';
} else {
    $nom_fichier = $slug . '_' . $db . '_' . $horo . '.sql' . ($gzip ? '.gz' : '');
    $tmp = tempnam(sys_get_temp_dir(), 'siges_exp_');
    try {
        ecole_dump_vers_fichier($db, $tmp, $gzip);
    } catch (\Throwable $ex) {
        @unlink($tmp);
        http_response_code(500);
        exit('Erreur pendant le dump : ' . $ex->getMessage());
    }
    journaliser_action('ecole_bd_export', $id,
        $e['code'] . ' — ' . $db . ' (' . _siges_exp_taille((int) filesize($tmp)) . ($gzip ? ', gz' : '') . ')');
    $mime = $gzip ? 'application/gzip' : 'application/sql';
}

while (ob_get_level() > 0) { ob_end_clean(); } // aucun octet parasite avant le fichier
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $nom_fichier . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);

function _siges_exp_taille(int $o): string {
    foreach (['o', 'Ko', 'Mo', 'Go'] as $u) {
        if ($o < 1024) return round($o, 1) . ' ' . $u;
        $o /= 1024;
    }
    return round($o, 1) . ' To';
}
