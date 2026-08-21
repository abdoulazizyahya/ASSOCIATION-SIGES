<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v20 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v20.php
//
//  Ajoute etablissement.signature (image de signature du chef
//  d'établissement, sélectionnée par l'admin dans les paramètres).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v20 — ABZ_MBE (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "✓ $label\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "⚠ $label — déjà en place (ignoré)\n";
        } else {
            echo "✗ $label — [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

// 1060 = Duplicate column name
run("etablissement.signature",
    "ALTER TABLE `etablissement` ADD COLUMN `signature` VARCHAR(255) NULL DEFAULT NULL AFTER `logo`",
    [1060]);

echo "\n=== Vérification ===\n";
print_r(db_one("SELECT id, sigle, logo, signature FROM etablissement WHERE id=1"));

echo "\n✅ Migration v20 terminée. Vous pouvez supprimer ce fichier après usage.\n";
