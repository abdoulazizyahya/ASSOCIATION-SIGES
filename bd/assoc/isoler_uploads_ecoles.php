<?php
// =====================================================================
//  bd/assoc/isoler_uploads_ecoles.php
//  Multi-établissement : range les fichiers uploadés (logo, signature,
//  pièces du dossier élève) de CHAQUE école dans un sous-dossier dédié
//  assets/uploads/etab/<code>/ (et dossiers_eleves/<code>/), au lieu des
//  noms fixes partagés (logo_etab.jpg…) qui provoquaient l'écrasement du
//  logo d'une école par une autre. Voir fonctions.php::upload_prefixe_etab().
//
//  Idempotent : une entrée déjà préfixée (contient « / ») est ignorée.
//  À exécuter UNE FOIS après le déploiement du correctif.
//
//  Usage :  php bd/assoc/isoler_uploads_ecoles.php [--dry-run]
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
$dry = in_array('--dry-run', $argv ?? [], true) || isset($_GET['dry']);
if (!annuaire_dispo()) { die("Annuaire absent — rien à faire (mono-école).\n"); }

$racine  = realpath(__DIR__ . '/../../assets/uploads');
$dossier = $racine . '/dossiers_eleves';

echo "=== Isolation des uploads par école ===\n" . ($dry ? "MODE DRY-RUN\n\n" : "\n");

function deplacer(string $src, string $dst, bool $dry): bool {
    if (!is_file($src)) { echo "   (absent : " . basename($src) . ")\n"; return false; }
    if (!is_dir(dirname($dst))) { if (!$dry) @mkdir(dirname($dst), 0775, true); }
    echo "   " . ($dry ? "[dry] " : "") . basename($src) . "  ->  " . str_replace(realpath(__DIR__ . '/../../assets/uploads') . DIRECTORY_SEPARATOR, '', $dst) . "\n";
    if ($dry) return true;
    return @rename($src, $dst) || (@copy($src, $dst) && @unlink($src));
}

foreach (assoc_all("SELECT id, code, db_name FROM etablissement WHERE actif=1 ORDER BY id") as $e) {
    $code   = strtolower(preg_replace('/[^a-z0-9]/i', '', $e['code']));
    $prefix = 'etab/' . $code . '/';
    echo strtoupper($e['code']) . " ({$e['db_name']})\n";

    $l = mysqli_connect(DB_HOST, DB_USER, DB_PASS, $e['db_name']);
    mysqli_set_charset($l, 'utf8mb4');

    // 1. logo + signature (table etablissement)
    $row = mysqli_fetch_assoc(mysqli_query($l, "SELECT logo, signature FROM etablissement LIMIT 1")) ?: [];
    foreach (['logo', 'signature'] as $col) {
        $val = $row[$col] ?? '';
        if ($val === '' || $val === null || strpos($val, '/') !== false) continue;   // vide ou déjà préfixé
        if (deplacer($racine . '/' . $val, $racine . '/' . $prefix . $val, $dry) && !$dry) {
            $st = mysqli_prepare($l, "UPDATE etablissement SET `$col` = ? LIMIT 1");
            $nv = $prefix . $val;
            mysqli_stmt_bind_param($st, 's', $nv);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
    }

    // 2. signatures des titulaires (si la table existe)
    if (mysqli_query($l, "SHOW TABLES LIKE 'signature_titulaire'")->num_rows) {
        $r = mysqli_query($l, "SELECT code, fichier FROM signature_titulaire WHERE fichier IS NOT NULL AND fichier <> '' AND fichier NOT LIKE '%/%'");
        while ($s = mysqli_fetch_assoc($r)) {
            if (deplacer($racine . '/' . $s['fichier'], $racine . '/' . $prefix . $s['fichier'], $dry) && !$dry) {
                $st = mysqli_prepare($l, "UPDATE signature_titulaire SET fichier = ? WHERE code = ?");
                $nv = $prefix . $s['fichier'];
                mysqli_stmt_bind_param($st, 'ss', $nv, $s['code']);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
        }
    }

    // 3. pièces du dossier élève
    if (mysqli_query($l, "SHOW TABLES LIKE 'dossier_eleve'")->num_rows) {
        $r = mysqli_query($l, "SELECT id, fichier FROM dossier_eleve WHERE fichier IS NOT NULL AND fichier <> '' AND fichier NOT LIKE '%/%'");
        $n = 0;
        while ($d = mysqli_fetch_assoc($r)) {
            if (deplacer($dossier . '/' . $d['fichier'], $dossier . '/' . $prefix . $d['fichier'], $dry) && !$dry) {
                $st = mysqli_prepare($l, "UPDATE dossier_eleve SET fichier = ? WHERE id = ?");
                $nv = $prefix . $d['fichier'];
                mysqli_stmt_bind_param($st, 'si', $nv, $d['id']);
                mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
                $n++;
            }
        }
        if ($n) echo "   $n pièce(s) de dossier déplacée(s)\n";
    }

    mysqli_close($l);
    echo "\n";
}

echo $dry ? "=== Fin (dry-run — rien écrit). ===\n" : "=== Terminé. ===\n";
