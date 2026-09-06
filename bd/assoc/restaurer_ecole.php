<?php
// =====================================================================
//  bd/assoc/restaurer_ecole.php
//  Restaure la base d'UNE école à partir d'un fichier de sauvegarde
//  (.sql ou .sql.gz) produit par :
//    - bd/assoc/sauvegarder_toutes_ecoles.php  (sauvegarde quotidienne)
//    - bd/assoc/sauvegarder_php.php
//    - l'export depuis l'interface association (ecole_bd_export.php)
//    - la sauvegarde de sécurité écrite avant import / migration / vidage
//
//  L'école est identifiée par son CODE (annuaire). Le contenu actuel de
//  la base est écrasé — une sauvegarde de sécurité est écrite AVANT
//  (bd/sauvegardes/avant_import_<base>_<horodatage>.sql.gz).
//
//  Usage :
//    php bd/assoc/restaurer_ecole.php <CODE> <fichier.sql[.gz]> [--oui]
//
//  Sans --oui : affiche ce qui serait fait et demande confirmation
//  (tape « OUI »). En mode non interactif, --oui est obligatoire.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';
require_once __DIR__ . '/../lib/ecole_maintenance.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); die("CLI uniquement.\n"); }

$a    = $argv ?? [];
$code = strtoupper(trim($a[1] ?? ''));
$file = $a[2] ?? '';
$oui  = in_array('--oui', $a, true);

if ($code === '' || $file === '') {
    fwrite(STDERR, "Usage : php bd/assoc/restaurer_ecole.php <CODE> <fichier.sql[.gz]> [--oui]\n");
    exit(2);
}
if (!annuaire_dispo()) { fwrite(STDERR, "Annuaire absent.\n"); exit(1); }

$e = assoc_one("SELECT * FROM etablissement WHERE code=?", [$code]);
if (!$e) { fwrite(STDERR, "École « $code » introuvable dans l'annuaire.\n"); exit(1); }

if (!is_file($file)) { fwrite(STDERR, "Fichier introuvable : $file\n"); exit(1); }
$sql = preg_match('/\.gz$/i', $file) ? ecole_maint_lire_gz($file) : @file_get_contents($file);
if ($sql === false || trim((string) $sql) === '') {
    fwrite(STDERR, "Impossible de lire le fichier (ou vide) : $file\n"); exit(1);
}

$etat = ecole_base_etat($e['db_name']);

echo "=== Restauration école $code ===\n";
echo "  Base cible     : {$e['db_name']}"
   . ($etat['existe'] ? " ({$etat['tables']} tables, {$etat['mo']} Mo) — SERA ÉCRASÉE\n" : " (absente — à créer d'abord via ecole_bd_creer)\n");
echo "  Fichier source : $file (" . round(strlen($sql) / 1024) . " Ko décompressés)\n";

if (!$etat['existe']) {
    fwrite(STDERR, "\nLa base n'existe pas. Créez-la d'abord (interface : « Créer la base »,\n"
                 . "ou php -r \"require 'connexion_assoc.php'; ...\").\n");
    exit(1);
}

if (!$oui) {
    if (!stream_isatty(STDIN)) {
        fwrite(STDERR, "\nMode non interactif : relancez avec --oui pour confirmer.\n");
        exit(3);
    }
    echo "\nTapez OUI pour écraser {$e['db_name']} par cette sauvegarde : ";
    $rep = trim((string) fgets(STDIN));
    if ($rep !== 'OUI') { echo "Annulé.\n"; exit(0); }
}

echo "\nRestauration en cours (sauvegarde de sécurité d'abord)…\n";
$r = ecole_importer_sql($e['db_name'], $sql);

echo ($r['ok'] ? "OK  " : "ÉCHEC : ") . $r['message'] . "\n";
if (!empty($r['backup'])) echo "Sauvegarde de sécurité de l'état précédent : " . $r['backup'] . "\n";

if ($r['ok']) {
    journaliser_action('ecole_bd_restauration', (int) $e['id'], basename($file) . " → {$r['tables']} tables");
}
exit($r['ok'] ? 0 : 1);
