<?php
// =====================================================================
//  bd/assoc/sauvegarder_php.php
//  Sauvegarde LOGIQUE en PHP pur (aucun exec / mysqldump) de l'annuaire
//  + de la base de CHAQUE école. Pour les hébergements mutualisés (Camoo
//  / cPanel) où les fonctions shell et mysqldump ne sont pas disponibles.
//
//  Écrit  bd/sauvegardes/<AAAAMMJJ_HHMMSS>/<code>_<db>.sql[.gz]
//  (dossier déjà gitignoré). Conserve les RETENTION dernières exécutions.
//  Le dump est écrit au fil de l'eau (pas de tout-en-mémoire).
//
//  Usage CLI :
//    php bd/assoc/sauvegarder_php.php [dossier_cible] [--gzip]
//  Usage HTTP (cron cPanel) — jeton obligatoire (BACKUP_TOKEN de config.local.php) :
//    /bd/assoc/sauvegarder_php.php?token=XXX&gzip=1
//
//  Cron cPanel (quotidien) :
//    /usr/bin/php /home/<compte>/<domaine>/bd/assoc/sauvegarder_php.php token=XXX --gzip
//
//  ⚠ Ne couvre PAS assets/uploads/ (photos, dossiers, logos, signatures) :
//    à inclure dans la sauvegarde FICHIERS du cPanel.
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

const RETENTION = 14;

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');

// ── Contrôle d'accès en HTTP ────────────────────────────────────────
if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (définir BACKUP_TOKEN dans config.local.php).\n");
    }
}

$args  = array_slice($argv ?? [], 1);
$gzip  = in_array('--gzip', $args, true) || !empty($_GET['gzip']);
$cible = null;
foreach ($args as $a) { if ($a !== '--gzip' && strpos($a, 'token=') !== 0) { $cible = $a; break; } }
$cible ??= __DIR__ . '/../sauvegardes';

if ($gzip && !function_exists('gzopen')) { $gzip = false; }

$horo = date('Ymd_His');
$dir  = rtrim($cible, '/\\') . '/' . $horo;
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { die("Impossible de créer $dir\n"); }

echo "=== Sauvegarde PHP $horo ===\n";

if (!annuaire_dispo()) {
    $bases = [['code' => 'MONO', 'db_name' => DB_NAME]];
} else {
    $bases = array_merge(
        [['code' => 'ASSOC', 'db_name' => DB_NAME_ASSOC]],
        assoc_all("SELECT code, db_name FROM etablissement ORDER BY id")
    );
}

$ok = 0; $ko = 0;
foreach ($bases as $b) {
    $fichier = $dir . '/' . strtolower($b['code']) . '_' . $b['db_name'] . '.sql' . ($gzip ? '.gz' : '');
    $t0 = microtime(true);
    try {
        dump_base_vers_fichier($b['db_name'], $fichier, $gzip);
        printf("  OK  %-8s %s  (%s, %.1fs)\n", $b['code'], basename($fichier), _taille(filesize($fichier)), microtime(true) - $t0);
        $ok++;
    } catch (\Throwable $e) {
        printf("  KO  %-8s %s : %s\n", $b['code'], $b['db_name'], $e->getMessage());
        @unlink($fichier);
        $ko++;
    }
}

// ── Rétention ───────────────────────────────────────────────────────
$dossiers = glob(rtrim($cible, '/\\') . '/[0-9]*_[0-9]*', GLOB_ONLYDIR) ?: [];
rsort($dossiers);
foreach (array_slice($dossiers, RETENTION) as $vieux) {
    array_map('unlink', glob("$vieux/*") ?: []);
    @rmdir($vieux);
    echo "  purge  " . basename($vieux) . "\n";
}

echo "=== $ok OK, $ko KO — $dir ===\n";
exit($ko === 0 ? 0 : 1);


// ── Dump logique d'une base, écrit au fil de l'eau ─────────────────
function dump_base_vers_fichier(string $db, string $fichier, bool $gzip): void {
    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
    mysqli_set_charset($l, 'utf8mb4');

    $fh = $gzip ? gzopen($fichier, 'wb6') : fopen($fichier, 'wb');
    if (!$fh) { mysqli_close($l); throw new RuntimeException("ouverture impossible : $fichier"); }
    $w = $gzip
        ? function (string $s) use ($fh) { gzwrite($fh, $s); }
        : function (string $s) use ($fh) { fwrite($fh, $s); };

    $w("-- Sauvegarde PHP de `$db` — " . date('c') . " (bd/assoc/sauvegarder_php.php)\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $tables = [];
    // Alias explicites : sous MariaDB, information_schema renvoie les noms de
    // colonnes en MAJUSCULES (TABLE_NAME), sous MySQL en minuscules.
    $r = mysqli_query($l, "SELECT table_name AS tn, table_type AS tt FROM information_schema.tables
                           WHERE table_schema = DATABASE() ORDER BY table_name");
    while ($row = mysqli_fetch_assoc($r)) $tables[$row['tn']] = $row['tt'];

    // 1) Tables de base : structure + données
    foreach ($tables as $t => $type) {
        if ($type === 'VIEW') continue;

        $cr  = mysqli_fetch_assoc(mysqli_query($l, "SHOW CREATE TABLE `$t`"));
        $w("\n-- --------------------------------------------------------\n");
        $w("DROP TABLE IF EXISTS `$t`;\n" . ($cr['Create Table'] ?? '') . ";\n\n");

        // Colonnes (ordre déclaré) — AVANT d'ouvrir le flux non bufferisé.
        $cn = mysqli_query($l, "SELECT column_name FROM information_schema.columns
                                WHERE table_schema = DATABASE() AND table_name = '"
                                . mysqli_real_escape_string($l, $t) . "' ORDER BY ordinal_position");
        $noms = [];
        while ($c = mysqli_fetch_row($cn)) $noms[] = $c[0];
        $cols = '`' . implode('`,`', $noms) . '`';

        $res = mysqli_query($l, "SELECT * FROM `$t`", MYSQLI_USE_RESULT);
        $buf = []; $len = 0;
        while ($row = mysqli_fetch_row($res)) {
            $vals = [];
            foreach ($row as $v) {
                $vals[] = ($v === null) ? 'NULL' : "'" . mysqli_real_escape_string($l, $v) . "'";
            }
            $tuple = '(' . implode(',', $vals) . ')';
            $buf[] = $tuple; $len += strlen($tuple);
            if ($len > 256 * 1024) {
                $w("INSERT INTO `$t` ($cols) VALUES " . implode(",\n", $buf) . ";\n");
                $buf = []; $len = 0;
            }
        }
        mysqli_free_result($res);
        if ($buf) $w("INSERT INTO `$t` ($cols) VALUES " . implode(",\n", $buf) . ";\n");
    }

    // 2) Vues (après les tables), DEFINER retiré (non portable)
    foreach ($tables as $t => $type) {
        if ($type !== 'VIEW') continue;
        $cr  = mysqli_fetch_assoc(mysqli_query($l, "SHOW CREATE VIEW `$t`"));
        $ddl = preg_replace('/DEFINER=`[^`]*`@`[^`]*` /', '', $cr['Create View'] ?? '');
        $w("\nDROP VIEW IF EXISTS `$t`;\n" . $ddl . ";\n");
    }

    $w("\nSET FOREIGN_KEY_CHECKS=1;\n");
    $gzip ? gzclose($fh) : fclose($fh);
    mysqli_close($l);
}

function _taille(int $o): string {
    foreach (['o', 'Ko', 'Mo', 'Go'] as $u) { if ($o < 1024) return round($o, 1) . ' ' . $u; $o /= 1024; }
    return round($o, 1) . ' To';
}
