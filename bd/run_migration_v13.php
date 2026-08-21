<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v13 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v13.php
//
//  Seuils du Conseil de Classe persistés par classe/année (table
//  critere_conseil) — voir pages/conseil_classe/index.php.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v13 — ABZ_MBE (mysqli) ===\n\n";

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

run("Table `critere_conseil`",
    "CREATE TABLE IF NOT EXISTS `critere_conseil` (
        `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_classe`             INT UNSIGNED NOT NULL,
        `id_annee`              INT UNSIGNED NOT NULL,
        `moyenne_admission`     DECIMAL(4,2) NULL DEFAULT NULL,
        `moyenne_exclusion`     DECIMAL(4,2) NULL DEFAULT NULL,
        `heures_absence_max`    SMALLINT UNSIGNED NULL DEFAULT NULL,
        `jours_exclusion_max`   SMALLINT UNSIGNED NULL DEFAULT NULL,
        `seuil_tableau_honneur` DECIMAL(4,2) NULL DEFAULT NULL,
        `seuil_encouragement`   DECIMAL(4,2) NULL DEFAULT NULL,
        `seuil_felicitation`    DECIMAL(4,2) NULL DEFAULT NULL,
        `maj_le`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_critere_classe_annee` (`id_classe`, `id_annee`),
        CONSTRAINT `fk_critere_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_critere_annee`  FOREIGN KEY (`id_annee`)  REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

echo "\n✅ Migration v13 terminée. Vous pouvez supprimer ce fichier après usage.\n";
