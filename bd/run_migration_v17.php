<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v17 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v17.php
//
//  Ajoute operateur_paiement.logo (nom de fichier dans
//  assets/uploads/operateurs/), géré depuis pages/paiements/operateurs.php.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v17 — ABZ_MBE (mysqli) ===\n\n";

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
run("operateur_paiement.logo",
    "ALTER TABLE `operateur_paiement` ADD COLUMN `logo` VARCHAR(255) NULL DEFAULT NULL AFTER `libelle`",
    [1060]);

$dir = __DIR__ . '/../assets/uploads/operateurs/';
if (!is_dir($dir)) { mkdir($dir, 0755, true); echo "✓ Dossier assets/uploads/operateurs/ créé\n"; }
else { echo "⚠ Dossier assets/uploads/operateurs/ déjà présent\n"; }

echo "\n✅ Migration v17 terminée. Vous pouvez supprimer ce fichier après usage.\n";
