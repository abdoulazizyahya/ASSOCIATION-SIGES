<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v42 (mysqli procédural, idempotent)
//  php bd/run_migration_v42.php   (ou via navigateur)
//  Section (Fr/An) rattachée au niveau, pas à la classe. Voir
//  bd/migration_v42.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v42 — jaynitaare_v2 (mysqli) ===\n\n";

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

$DEJA_LA = [1060, 1061, 1826, 1005, 1050];
// 1091 = colonne déjà absente (DROP COLUMN déjà exécuté).
$DEJA_ABSENTE = [1091];

// N'exécute la reprise des valeurs (étape 2) QUE si classe.Section existe
// encore — sinon (relance après un premier passage réussi) la requête
// échouerait avec "colonne inconnue" alors que tout est déjà en ordre.
$classe_a_section = (bool) db_val(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='classe' AND COLUMN_NAME='Section'"
);

run(
    "niveau.Section",
    "ALTER TABLE `niveau` ADD COLUMN `Section` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fr' AFTER `LibelleNiveau`",
    $DEJA_LA
);

if ($classe_a_section) {
    run(
        "Reprise des valeurs classe.Section -> niveau.Section",
        "UPDATE `niveau` n JOIN `classe` c ON c.Niveau = n.LibelleNiveau SET n.Section = c.Section"
    );
    run(
        "classe.Section (retrait)",
        "ALTER TABLE `classe` DROP COLUMN `Section`",
        $DEJA_ABSENTE
    );
} else {
    echo "--  classe.Section déjà absente — étapes 2/3 ignorées (déjà migré).\n";
}

echo "\n=== Vérification ===\n";
$niveau_cols = array_column(db_all("SHOW COLUMNS FROM niveau"), 'Field');
echo in_array('Section', $niveau_cols, true) ? "  niveau.Section présente.\n" : "  ECHEC : colonne absente sur niveau !\n";
$classe_cols = array_column(db_all("SHOW COLUMNS FROM classe"), 'Field');
echo !in_array('Section', $classe_cols, true) ? "  classe.Section bien retirée.\n" : "  ECHEC : classe.Section toujours présente !\n";
foreach (db_all("SELECT LibelleNiveau, Section FROM niveau ORDER BY OrdreNiveau") as $n) {
    echo "  Niveau {$n['LibelleNiveau']} -> Section {$n['Section']}\n";
}

echo "\nMigration v42 terminée.\n";
