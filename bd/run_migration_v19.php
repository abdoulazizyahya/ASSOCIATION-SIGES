<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v19 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v19.php
//
//  Crée reglage_mention_bulletin : seuils des mentions automatiques du
//  bulletin (Tableau d'honneur, Encouragement, Félicitation, Avertissement/
//  Blâme travail, Avertissement/Blâme conduite), configurables par
//  l'administrateur (pages/parametres/index.php, onglet "Mentions").
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v19 — ABZ_MBE (mysqli) ===\n\n";

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

run("Table `reglage_mention_bulletin`",
    "CREATE TABLE IF NOT EXISTS `reglage_mention_bulletin` (
        `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_annee`                  INT UNSIGNED NOT NULL,
        `moy_tableau_honneur`       DECIMAL(4,2) NOT NULL DEFAULT 12.00,
        `heures_max_tableau_honneur` SMALLINT UNSIGNED NOT NULL DEFAULT 8,
        `moy_encouragement`         DECIMAL(4,2) NOT NULL DEFAULT 14.00,
        `moy_felicitation`          DECIMAL(4,2) NOT NULL DEFAULT 15.00,
        `moy_avert_travail_min`     DECIMAL(4,2) NOT NULL DEFAULT 5.00,
        `moy_avert_travail_max`     DECIMAL(4,2) NOT NULL DEFAULT 7.30,
        `moy_blame_travail_max`     DECIMAL(4,2) NOT NULL DEFAULT 5.00,
        `heures_avert_conduite_min` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
        `heures_avert_conduite_max` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        `heures_blame_conduite_min` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_reglage_annee` (`id_annee`),
        CONSTRAINT `fk_reglage_mention_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");

echo "\n✅ Migration v19 terminée. Vous pouvez supprimer ce fichier après usage.\n";
