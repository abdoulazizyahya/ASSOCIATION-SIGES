<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v23 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v23.php
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v23 — ABZ_MBE (mysqli) ===\n\n";

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

// 1062 = Duplicate entry (déjà seedé)
run("Seed tresorier_apee",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('tresorier_apee', 'Trésorier de l\'APEE', 'INTENDANT')",
    [1062]);

// 1060 = Duplicate column name
run("obligation_frais.code_fixe",
    "ALTER TABLE `obligation_frais` ADD COLUMN `code_fixe` VARCHAR(20) NULL DEFAULT NULL AFTER `libelle`",
    [1060]);

// 1091 = "can't DROP ... check that column/key exists" (déjà fait)
run("paiement_frais.numero_recu : UNIQUE -> index simple (regroupement multi-frais)",
    "ALTER TABLE `paiement_frais` DROP INDEX `uk_numero_recu`, ADD INDEX `idx_numero_recu` (`numero_recu`)",
    [1091]);

run("Table reglage_paiement", "
    CREATE TABLE IF NOT EXISTS `reglage_paiement` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_annee` INT UNSIGNED NOT NULL,
      `montant_frais_operateur` DECIMAL(10,2) NOT NULL DEFAULT 200.00,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uk_reglage_paiement_annee` (`id_annee`),
      CONSTRAINT `fk_reglage_paiement_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");

echo "\n=== Vérification ===\n";
print_r(db_all("SELECT * FROM signature_titulaire"));
echo "\n";
print_r(db_one("DESCRIBE obligation_frais") ? db_all("DESCRIBE obligation_frais") : []);

echo "\n✅ Migration v23 terminée. Vous pouvez supprimer ce fichier après usage.\n";
