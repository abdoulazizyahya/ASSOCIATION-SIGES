<?php
// =====================================================================
//  bd/assoc/maj_assoc.php
//  Applique à une base annuaire DÉJÀ installée les évolutions de schéma
//  postérieures à sa création (colonnes / tables ajoutées à
//  schema_assoc.sql après coup). Idempotent : relançable sans risque.
//
//  À lancer après une mise à jour du code :
//    php bd/assoc/maj_assoc.php
//
//  (schema_assoc.sql via installer.php crée déjà tout pour une NOUVELLE
//  installation ; ce script ne sert qu'aux bases existantes.)
// =====================================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../connexion_assoc.php';

if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');
if (!annuaire_dispo()) { die("Annuaire absent — rien à mettre à jour.\n"); }

global $link_assoc;
$db = DB_NAME_ASSOC;

function col_existe(string $table, string $col): bool {
    return (bool) assoc_val(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
        [$table, $col]
    );
}
function table_existe(string $table): bool {
    return (bool) assoc_val(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?", [$table]
    );
}

$fait = [];

echo "=== Mise à jour de l'annuaire ($db) ===\n";

// ── 2FA : membre.totp_secret / membre.totp_actif ──────────────────
if (!col_existe('membre', 'totp_secret')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `totp_secret` varchar(64) DEFAULT NULL AFTER `actif`");
    $fait[] = "membre.totp_secret ajoutée";
}
if (!col_existe('membre', 'totp_actif')) {
    mysqli_query($link_assoc, "ALTER TABLE `membre` ADD COLUMN `totp_actif` tinyint(1) NOT NULL DEFAULT 0 AFTER `totp_secret`");
    $fait[] = "membre.totp_actif ajoutée";
}

// ── Limitation des connexions : table login_echec ─────────────────
if (!table_existe('login_echec')) {
    mysqli_query($link_assoc,
        "CREATE TABLE `login_echec` (
           `id`    bigint      NOT NULL AUTO_INCREMENT,
           `login` varchar(50) DEFAULT NULL,
           `ip`    varchar(45) DEFAULT NULL,
           `date`  datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           KEY `k_login` (`login`,`date`),
           KEY `k_ip` (`ip`,`date`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $fait[] = "table login_echec créée";
}

if ($fait) {
    foreach ($fait as $f) echo "  OK  $f\n";
} else {
    echo "  — déjà à jour, rien à faire.\n";
}
echo "=== Terminé. ===\n";
