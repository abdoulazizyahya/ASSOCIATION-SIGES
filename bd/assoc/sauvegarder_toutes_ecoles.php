<?php
// =====================================================================
//  bd/assoc/sauvegarder_toutes_ecoles.php
//  Sauvegarde (mysqldump) de l'annuaire + de la base de CHAQUE école,
//  dans un dossier horodaté. À planifier (Planificateur de tâches
//  Windows / cron) une fois par jour.
//
//  Usage :  php bd/assoc/sauvegarder_toutes_ecoles.php [dossier_cible] [--gzip]
//    dossier_cible : défaut = <projet>/bd/sauvegardes
//
//  Nettoyage : conserve les 14 dernières exécutions (RETENTION).
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

const RETENTION = 14;

$args   = array_values(array_filter($argv ?? [], fn($a) => $a !== $argv[0]));
$gzip   = in_array('--gzip', $args, true);
$cible  = null;
foreach ($args as $a) { if ($a !== '--gzip') { $cible = $a; break; } }
$cible ??= __DIR__ . '/../sauvegardes';

// ── Localiser mysqldump (WAMP : mysql* ou mariadb*) ──────────────────
$dump = getenv('MYSQLDUMP') ?: null;
if (!$dump || !is_file($dump)) {
    $dump = null;
    foreach (glob('C:/wamp64/bin/{mysql,mariadb}/*/bin/mysqldump.exe', GLOB_BRACE) ?: [] as $c) { $dump = $c; break; }
    foreach (['/usr/bin/mysqldump', '/usr/bin/mariadb-dump'] as $c) { if (!$dump && is_file($c)) $dump = $c; }
}
if (!$dump) { fwrite(STDERR, "mysqldump introuvable — définir la variable d'env MYSQLDUMP.\n"); exit(1); }

$horo = date('Ymd_His');
$dir  = rtrim($cible, '/\\') . '/' . $horo;
if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { fwrite(STDERR, "Impossible de créer $dir\n"); exit(1); }

echo "=== Sauvegarde $horo ===\n";
if (!annuaire_dispo()) {
    // Mono-école : juste DB_NAME.
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
    $cmd = escapeshellarg($dump)
         . ' --host=' . escapeshellarg(DB_HOST)
         . ' --user=' . escapeshellarg(DB_USER)
         . (DB_PASS !== '' ? ' --password=' . escapeshellarg(DB_PASS) : '')
         . ' --single-transaction --quick --routines --events --default-character-set=utf8mb4 '
         . escapeshellarg($b['db_name']);
    $cmd .= $gzip ? ' | gzip' : '';
    $cmd .= ' > ' . escapeshellarg($fichier) . ' 2> ' . escapeshellarg($fichier . '.err');

    $t0 = microtime(true);
    system($cmd, $rc);
    $taille = is_file($fichier) ? filesize($fichier) : 0;
    $err    = @file_get_contents($fichier . '.err');
    if ($rc === 0 && $taille > 0 && trim((string) $err) === '') {
        @unlink($fichier . '.err');
        printf("  OK  %-8s %s  (%s, %.1fs)\n", $b['code'], basename($fichier), _taille($taille), microtime(true) - $t0);
        $ok++;
    } else {
        printf("  KO  %-8s %s\n", $b['code'], trim((string) $err) ?: "code retour $rc");
        $ko++;
    }
}

// ── Copie hors-site ─────────────────────────────────────────────────
//  Optionnelle, pilotée par config(.local).php :
//   - BACKUP_OFFSITE_DIR : dossier destination (partage réseau, disque
//     monté, dossier synchronisé Drive/Dropbox…). Le dossier horodaté y
//     est recopié tel quel.
//   - BACKUP_OFFSITE_CMD : commande shell exécutée avec le chemin du
//     dossier horodaté en argument (ex. 'rclone copy' , 'aws s3 sync' ,
//     un script scp). Reçoit "<dossier> <nom_horodaté>".
//  Les deux peuvent être définies ; DIR d'abord, puis CMD.
$offsite_ok = true;
if (defined('BACKUP_OFFSITE_DIR') && BACKUP_OFFSITE_DIR !== '') {
    $dest = rtrim(BACKUP_OFFSITE_DIR, '/\\') . '/' . $horo;
    if (!is_dir($dest) && !@mkdir($dest, 0775, true)) {
        echo "  hors-site KO : impossible de créer $dest\n"; $offsite_ok = false;
    } else {
        $n = 0;
        foreach (glob("$dir/*") ?: [] as $f) {
            if (@copy($f, $dest . '/' . basename($f))) $n++;
            else { $offsite_ok = false; echo "  hors-site KO : copie de " . basename($f) . "\n"; }
        }
        echo "  hors-site  $n fichier(s) → $dest\n";
    }
}
if (defined('BACKUP_OFFSITE_CMD') && BACKUP_OFFSITE_CMD !== '') {
    $cmd = BACKUP_OFFSITE_CMD . ' ' . escapeshellarg($dir) . ' ' . escapeshellarg($horo) . ' 2>&1';
    exec($cmd, $out, $rc);
    echo "  hors-site cmd (rc=$rc) : " . trim(implode(' | ', array_slice($out, -3))) . "\n";
    if ($rc !== 0) $offsite_ok = false;
}

// ── Rétention ───────────────────────────────────────────────────────
$dossiers = glob(rtrim($cible, '/\\') . '/[0-9]*_[0-9]*', GLOB_ONLYDIR) ?: [];
rsort($dossiers);
foreach (array_slice($dossiers, RETENTION) as $vieux) {
    array_map('unlink', glob("$vieux/*") ?: []);
    @rmdir($vieux);
    echo "  purge  " . basename($vieux) . "\n";
}

echo "=== $ok OK, $ko KO" . ($offsite_ok ? '' : ', hors-site INCOMPLET') . " — $dir ===\n";
exit(($ko === 0 && $offsite_ok) ? 0 : 1);

function _taille(int $o): string {
    foreach (['o', 'Ko', 'Mo', 'Go'] as $u) { if ($o < 1024) return round($o, 1) . ' ' . $u; $o /= 1024; }
    return round($o, 1) . ' To';
}
