<?php
// =====================================================================
//  bd/assoc/importer_dumps.php
//  Importe des dumps SQL (annuaire + écoles) directement via la
//  connexion MySQL de l'application — utile quand phpMyAdmin de
//  l'hébergeur mutualisé (Camoo) est inutilisable.
//
//  Les fichiers .sql sont lus depuis  bd/assoc/dumps/  (créer ce dossier
//  et y téléverser les fichiers de _DEPLOY_CAMOO/sql_camoo/). Chaque
//  fichier doit contenir en tête «  USE `nom_base`;  » : c'est cette base
//  (déjà créée dans le panneau de l'hébergeur) qui reçoit l'import.
//
//  Sécurité : en HTTP, jeton obligatoire  ?token=<BACKUP_TOKEN>  (défini
//  dans config.local.php). N'accepte que des bases dont le nom commence
//  par le même préfixe que DB_NAME_ASSOC (ex. « beeroc11073_ »).
//
//  Usage HTTP :
//    /bd/assoc/importer_dumps.php?token=XXX              (aperçu : liste)
//    /bd/assoc/importer_dumps.php?token=XXX&go=1         (importe)
//    /bd/assoc/importer_dumps.php?token=XXX&go=1&reset=1 (vide d'abord les tables)
//    ...&only=01_promeducam_assoc.sql                    (un seul fichier)
//  Usage CLI :
//    php bd/assoc/importer_dumps.php [--go] [--reset] [--dir=chemin] [--only=fichier.sql]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

@set_time_limit(0);
mysqli_report(MYSQLI_REPORT_OFF);

$est_cli = (PHP_SAPI === 'cli');
if (!$est_cli) header('Content-Type: text/plain; charset=utf-8');

$args = array_slice($argv ?? [], 1);
$opt  = function (string $k, $def = null) use ($args, $est_cli) {
    if ($est_cli) {
        foreach ($args as $a) {
            if ($a === "--$k") return true;
            if (strpos($a, "--$k=") === 0) return substr($a, strlen($k) + 3);
        }
        return $def;
    }
    return $_GET[$k] ?? $_POST[$k] ?? $def;
};

// ── Contrôle d'accès (HTTP) ─────────────────────────────────────────
if (!$est_cli) {
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    if (!defined('BACKUP_TOKEN') || BACKUP_TOKEN === '' || !hash_equals(BACKUP_TOKEN, $token)) {
        http_response_code(403);
        die("Accès refusé : jeton invalide (BACKUP_TOKEN de config.local.php).\n");
    }
}

$dir   = rtrim((string) ($opt('dir') ?: __DIR__ . '/dumps'), "/\\");
$go    = (bool) $opt('go');
$reset = (bool) $opt('reset');
$only  = (string) ($opt('only') ?: '');

// Préfixe autorisé pour les bases cibles (ex. « beeroc11073_ »).
$prefixe = '';
if (defined('DB_NAME_ASSOC') && ($p = strrpos(DB_NAME_ASSOC, '_')) !== false) {
    $prefixe = substr(DB_NAME_ASSOC, 0, $p + 1);
}

echo "=== Import des dumps SQL ===\n";
echo "Dossier : $dir\n";
echo "Serveur : " . DB_HOST . "  ·  user : " . DB_USER . "  ·  préfixe autorisé : "
   . ($prefixe !== '' ? $prefixe : '(aucun — refus)') . "\n";
echo $go ? ("Mode : IMPORT" . ($reset ? " + RESET (tables vidées d'abord)" : "") . "\n")
         : "Mode : APERÇU (ajouter &go=1 pour lancer)\n";
echo str_repeat('-', 60) . "\n";

if (!is_dir($dir)) {
    die("✗ Dossier introuvable : $dir\n  → crée-le et téléverse-y les .sql de _DEPLOY_CAMOO/sql_camoo/\n");
}
if ($prefixe === '') {
    die("✗ DB_NAME_ASSOC ne permet pas de déduire un préfixe sûr. Abandon.\n");
}

$fichiers = glob($dir . '/*.sql') ?: [];
sort($fichiers);
if ($only !== '') {
    $fichiers = array_values(array_filter($fichiers, fn($f) => basename($f) === $only));
}
if (!$fichiers) {
    die("✗ Aucun fichier .sql" . ($only !== '' ? " nommé « $only »" : '') . " dans $dir\n");
}

/**
 * Découpe un dump en instructions exécutables une par une.
 * Suffisant pour des dumps mysqldump « propres » (pas de routines /
 * triggers / DELIMITER). Respecte les chaînes '...' "..." `...` et les
 * commentaires -- , # et /* *\/.
 */
function decouper_sql(string $sql): array {
    $out = [];
    $buf = '';
    $n   = strlen($sql);
    $q   = '';                     // guillemet courant
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== '') {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $n) { $buf .= $sql[++$i]; continue; }
            if ($c === $q) $q = '';
            continue;
        }
        // hors chaîne
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
        if ($c === '-' && substr($sql, $i, 3) === '-- ') {
            $j = strpos($sql, "\n", $i); $i = ($j === false ? $n : $j); continue;
        }
        if ($c === '#') {
            $j = strpos($sql, "\n", $i); $i = ($j === false ? $n : $j); continue;
        }
        if ($c === '/' && $i + 1 < $n && $sql[$i + 1] === '*') {
            $j = strpos($sql, '*/', $i + 2);
            $bloc = substr($sql, $i, ($j === false ? $n : $j + 2) - $i);
            // Garder les commentaires exécutables /*!... */
            if (preg_match('~^/\*!\d*~', $bloc)) {
                $buf .= preg_replace('~^/\*!\d*\s?|\s?\*/$~', '', $bloc) . "\n";
            }
            $i = ($j === false ? $n : $j + 1);
            continue;
        }
        if ($c === ';') {
            $t = trim($buf);
            if ($t !== '') $out[] = $t;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $t = trim($buf);
    if ($t !== '') $out[] = $t;
    return $out;
}

$bilan = [];
foreach ($fichiers as $f) {
    $nom = basename($f);
    $sql = file_get_contents($f);
    if ($sql === false) { echo "✗ $nom : lecture impossible\n"; continue; }

    // Base cible : « USE `x`; » puis « -- Cible : x ».
    $db = null;
    if (preg_match('~USE\s+`([^`]+)`~i', $sql, $m))            $db = $m[1];
    elseif (preg_match('~--\s*Cible\s*:\s*([A-Za-z0-9_]+)~i', $sql, $m)) $db = $m[1];

    echo "\n▶ $nom  →  base « " . ($db ?? '?') . " »\n";
    if ($db === null) { echo "  ✗ base cible introuvable dans le fichier — ignoré\n"; continue; }
    if (strpos($db, $prefixe) !== 0) {
        echo "  ✗ « $db » hors préfixe « $prefixe » — ignoré (sécurité)\n";
        continue;
    }

    $link = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, $db);
    if (!$link) {
        echo "  ✗ connexion à « $db » impossible : " . mysqli_connect_error() . "\n";
        echo "     → crée cette base dans le panneau de l'hébergeur et rattache "
           . DB_USER . " (tous privilèges).\n";
        $bilan[$nom] = 'base absente';
        continue;
    }
    mysqli_set_charset($link, 'utf8mb4');

    $tables = [];
    if ($r = mysqli_query($link, 'SHOW TABLES')) {
        while ($row = mysqli_fetch_row($r)) $tables[] = $row[0];
    }
    echo "  État actuel : " . count($tables) . " table(s)\n";

    if (!$go) {
        $stmts = decouper_sql($sql);
        echo "  Aperçu : " . count($stmts) . " instructions à exécuter\n";
        mysqli_close($link);
        continue;
    }

    if ($tables) {
        if (!$reset) {
            echo "  ⏭  base non vide → ignorée. Relance avec &reset=1 pour l'écraser.\n";
            mysqli_close($link);
            $bilan[$nom] = 'ignorée (non vide)';
            continue;
        }
        mysqli_query($link, 'SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $t) mysqli_query($link, 'DROP TABLE IF EXISTS `' . $t . '`');
        mysqli_query($link, 'SET FOREIGN_KEY_CHECKS=1');
        echo "  ↺ " . count($tables) . " table(s) supprimée(s)\n";
    }

    // Retirer USE / CREATE DATABASE, exécuter le reste.
    $sql = preg_replace('~^\s*USE\s+`[^`]+`\s*;\s*$~im', '', $sql);
    $sql = preg_replace('~^\s*CREATE\s+DATABASE[^;]*;\s*$~im', '', $sql);

    $stmts = decouper_sql($sql);
    mysqli_query($link, 'SET FOREIGN_KEY_CHECKS=0');
    mysqli_query($link, "SET SQL_MODE='NO_ENGINE_SUBSTITUTION'");

    $ok = 0; $err = 0; $premiere_erreur = '';
    foreach ($stmts as $s) {
        if ($s === '' || preg_match('~^SET\s+(FOREIGN_KEY_CHECKS|UNIQUE_CHECKS|SQL_NOTES|TIME_ZONE|NAMES|CHARACTER_SET|@OLD)~i', $s)) {
            // laisser passer sans compter comme donnée
            @mysqli_query($link, $s);
            continue;
        }
        if (@mysqli_query($link, $s)) {
            $ok++;
        } else {
            $err++;
            if ($premiere_erreur === '') {
                $premiere_erreur = mysqli_error($link) . "\n     …dans : " . substr(preg_replace('~\s+~', ' ', $s), 0, 160);
            }
        }
    }
    mysqli_query($link, 'SET FOREIGN_KEY_CHECKS=1');

    $nbt = 0;
    if ($r = mysqli_query($link, 'SHOW TABLES')) $nbt = mysqli_num_rows($r);
    mysqli_close($link);

    echo "  ✔ $ok instruction(s) OK, $err erreur(s) — $nbt table(s) au final\n";
    if ($premiere_erreur !== '') echo "  ⚠ 1re erreur : $premiere_erreur\n";
    $bilan[$nom] = "$ok ok / $err err / $nbt tables";
}

echo "\n" . str_repeat('=', 60) . "\n";
echo $go ? "BILAN :\n" : "APERÇU terminé — ajoute &go=1 pour importer.\n";
foreach ($bilan as $k => $v) echo "  · $k : $v\n";
if ($go) {
    echo "\nEnsuite :\n";
    echo "  1. /bd/assoc/importer_dumps.php  (supprimer ce fichier + le dossier dumps/ après usage)\n";
    echo "  2. sur l'annuaire : exécuter annuaire_fix_db_names_CAMOO.sql (recale les db_name)\n";
    echo "  3. /bd/assoc/maj_assoc.php  puis  /bd/assoc/migrer_toutes_ecoles.php\n";
}
