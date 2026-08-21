<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v41 (mysqli procédural, idempotent)
//  php bd/run_migration_v41.php   (ou via navigateur)
//  Section anglophone (bulletin en anglais). Voir bd/migration_v41.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v41 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1060 = colonne déjà là.
run(
    "classe.Section",
    "ALTER TABLE `classe` ADD COLUMN `Section` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fr' AFTER `Niveau`",
    [1060]
);

run(
    "competence 3A->3B (groupe 9, recodage)",
    "UPDATE `competence` SET `code_comp` = '3B' WHERE `code_comp` = '3A' AND `id_groupe_comp` = 9"
);
run(
    "competence 3A manquante (groupe 9, insertion)",
    "INSERT INTO `competence` (`code_comp`, `nom_comp`, `id_groupe_comp`)
        SELECT '3A', 'Practise social values', 9
        WHERE NOT EXISTS (SELECT 1 FROM `competence` WHERE `code_comp` = '3A' AND `id_groupe_comp` = 9)"
);
run(
    "competence 6A1->6A (groupe 12, recodage)",
    "UPDATE `competence` SET `code_comp` = '6A' WHERE `code_comp` = '6A1' AND `id_groupe_comp` = 12"
);

echo "\n=== Vérification ===\n";
$cols = array_column(db_all("SHOW COLUMNS FROM classe"), 'Field');
echo in_array('Section', $cols, true) ? "  classe.Section présente.\n" : "  ECHEC : colonne Section absente !\n";

$an9 = db_all("SELECT code_comp, nom_comp FROM competence WHERE id_groupe_comp=9 ORDER BY code_comp");
echo "  Groupe 9 (An) : " . count($an9) . " ligne(s) — " . implode(', ', array_column($an9, 'code_comp')) . "\n";
$an12 = db_all("SELECT code_comp, nom_comp FROM competence WHERE id_groupe_comp=12 ORDER BY code_comp");
echo "  Groupe 12 (An) : " . count($an12) . " ligne(s) — " . implode(', ', array_column($an12, 'code_comp')) . "\n";

echo "\nMigration v41 terminée.\n";
