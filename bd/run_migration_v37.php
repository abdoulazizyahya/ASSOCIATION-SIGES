<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v37 (mysqli procédural, idempotent)
//  php bd/run_migration_v37.php   (ou via navigateur)
//  Notes zéro automatiques, éligibilité au classement, annulation de
//  trimestre (piste française). Voir bd/migration_v37.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v37 — jaynitaare_v2 (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "OK  $label\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "--  $label - déjà en place (ignoré)\n";
        } else {
            echo "ERR $label - [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

// 1060 = colonne déjà là, 1050 = table déjà là.
run(
    "Colonne moyenne_trimestre.classable",
    "ALTER TABLE `moyenne_trimestre` ADD COLUMN `classable` tinyint(1) NOT NULL DEFAULT 1 AFTER `moy`",
    [1060]
);

run(
    "Table trimestre_annule",
    "CREATE TABLE IF NOT EXISTS `trimestre_annule` (
        `id`             int NOT NULL AUTO_INCREMENT,
        `id_eleve`       int unsigned NOT NULL,
        `id_trim`        int NOT NULL,
        `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
        `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `annule_le`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_trim_annule_eleve_trim_annee` (`id_eleve`, `id_trim`, `val_annee`),
        KEY `id_trim` (`id_trim`),
        CONSTRAINT `fk_trim_annule_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_trim_annule_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_trim_annule_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_trim_annule_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    [1050]
);

echo "\n=== Vérification ===\n";
$cols = array_column(db_all("SHOW COLUMNS FROM moyenne_trimestre"), 'Field');
echo in_array('classable', $cols, true) ? "  moyenne_trimestre.classable présente.\n" : "  ECHEC : colonne classable absente !\n";

$table = db_all("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='trimestre_annule'");
echo $table ? "  Table trimestre_annule présente.\n" : "  ECHEC : table trimestre_annule absente !\n";

echo "\nMigration v37 terminée.\n";
