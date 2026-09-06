<?php
// association/ecole_bd_export.php — Export (dump SQL) de la base d'UNE
// école. Superadmin association uniquement. Le fichier est téléchargé
// (aucune écriture côté serveur, hormis un fichier temporaire supprimé
// aussitôt). Ajouter &gzip=1 pour un .sql.gz.
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

$db   = $e['db_name'];
$gzip = !empty($_GET['gzip']) && function_exists('gzopen');

try {
    ecole_maint_garde_base($db);
} catch (\Throwable $ex) {
    http_response_code(400);
    exit('Export impossible : ' . $ex->getMessage());
}

$nom_fichier = strtolower($e['code']) . '_' . $db . '_' . date('Ymd_His') . '.sql' . ($gzip ? '.gz' : '');

// Dump vers un fichier temporaire puis streaming (gère gzip + Content-Length).
$tmp = tempnam(sys_get_temp_dir(), 'siges_exp_');
try {
    ecole_dump_vers_fichier($db, $tmp, $gzip);
} catch (\Throwable $ex) {
    @unlink($tmp);
    http_response_code(500);
    exit('Erreur pendant le dump : ' . $ex->getMessage());
}

journaliser_action('ecole_bd_export', $id, $e['code'] . ' — ' . $db . ' (' . _siges_exp_taille(filesize($tmp)) . ($gzip ? ', gz' : '') . ')');

while (ob_get_level() > 0) { ob_end_clean(); } // aucun octet parasite avant le fichier
header('Content-Type: ' . ($gzip ? 'application/gzip' : 'application/sql'));
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
