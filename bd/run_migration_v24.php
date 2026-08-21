<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v24 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v24.php
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v24 — ABZ_MBE (mysqli) ===\n\n";

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
run("reglage_paiement.couleur_fond_1/2/3",
    "ALTER TABLE `reglage_paiement`
       ADD COLUMN `couleur_fond_1` VARCHAR(7) NOT NULL DEFAULT '#FFF6C8' AFTER `montant_frais_operateur`,
       ADD COLUMN `couleur_fond_2` VARCHAR(7) NOT NULL DEFAULT '#FFCDD2' AFTER `couleur_fond_1`,
       ADD COLUMN `couleur_fond_3` VARCHAR(7) NOT NULL DEFAULT '#CDE8CD' AFTER `couleur_fond_2`",
    [1060]);

echo "\n=== Vérification ===\n";
print_r(db_one("DESCRIBE reglage_paiement") ? db_all("DESCRIBE reglage_paiement") : []);

echo "\n✅ Migration v24 terminée. Vous pouvez supprimer ce fichier après usage.\n";
