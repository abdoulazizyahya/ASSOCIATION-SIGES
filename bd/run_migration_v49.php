<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v49 (mysqli procédural, idempotent)
//  php bd/run_migration_v49.php   (ou via navigateur)
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v49 — jaynitaare_v2 (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "OK  $label (" . mysqli_affected_rows($link) . " ligne(s))\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "--  $label - déjà en place (ignoré)\n";
        } else {
            echo "ERR $label - [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

$DEJA_LA = [1091]; // colonne déjà absente

run(
    "composer_sequence_arabe.note (suppression)",
    "ALTER TABLE `composer_sequence_arabe` DROP COLUMN `note`",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
$cols = array_column(db_all("SHOW COLUMNS FROM composer_sequence_arabe"), 'Field');
echo "note : " . (in_array('note', $cols, true) ? "TOUJOURS PRÉSENTE" : "supprimée") . "\n";

echo "\nMigration v49 terminée.\n";
