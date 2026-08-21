<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v36 (mysqli procédural, idempotent)
//  php bd/run_migration_v36.php   (ou via navigateur)
//  Passage en classe supérieure automatique : classe.classe_suivante.
//  Voir bd/migration_v36.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v36 — jaynitaare_v2 (mysqli) ===\n\n";

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

// Codes ignorés en cas de ré-exécution : 1060 colonne déjà là, 1061/1826/1005
// clé/contrainte déjà là, 1050 table déjà là.
$DEJA_LA = [1060, 1061, 1826, 1005, 1050];

run(
    "Colonne classe.classe_suivante",
    "ALTER TABLE `classe` ADD COLUMN `classe_suivante` int DEFAULT NULL AFTER `Niveau`",
    $DEJA_LA
);
run(
    "Contrainte fk_classe_classe_suivante",
    "ALTER TABLE `classe` ADD CONSTRAINT `fk_classe_classe_suivante` FOREIGN KEY (`classe_suivante`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
$nb_col = db_val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='classe' AND COLUMN_NAME='classe_suivante'");
echo "  colonne classe_suivante présente : " . ($nb_col ? 'oui' : 'NON — ÉCHEC') . "\n";

echo "\nMigration v36 terminée.\n";
