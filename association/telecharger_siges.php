<?php
// association/telecharger_siges.php — télécharge le zip « SIGES vierge »
// (code + bibliothèques, aucune donnée d'école) pour installer SIGES sur un
// autre ordinateur via installation/install.php (06/11/2026).
// Voir bd/lib/paquet_installation.php pour le contenu du paquet.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../noyau/connexion.php';
require_once __DIR__ . '/../noyau/fonctions.php';
require_once __DIR__ . '/../bd/lib/paquet_installation.php';
exiger_superadmin_association();

@set_time_limit(300);
$tmp = tempnam(sys_get_temp_dir(), 'siges_paquet_');
try {
    $r = paquet_installation_construire($tmp);
} catch (\Throwable $e) {
    @unlink($tmp);
    http_response_code(500);
    die('Préparation du paquet impossible : ' . htmlspecialchars($e->getMessage()));
}
journaliser_action('telechargement_paquet_siges', null, $r['fichiers'] . ' fichiers');

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="SIGES_installation_' . date('Y-m-d') . '.zip"');
header('Content-Length: ' . $r['octets']);
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
exit;
