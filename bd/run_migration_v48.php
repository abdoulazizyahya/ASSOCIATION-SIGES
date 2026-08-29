<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v48 (mysqli procédural, idempotent)
//  php bd/run_migration_v48.php   (ou via navigateur)
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v48 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1091 colonne déjà absente (ignore-code, même liste que les migrations précédentes).
$DEJA_LA = [1091];

run(
    "composer_sequence_arabe.etat_composition (suppression)",
    "ALTER TABLE `composer_sequence_arabe` DROP COLUMN `etat_composition`",
    $DEJA_LA
);
run(
    "composer_sequence_arabe.justification (suppression)",
    "ALTER TABLE `composer_sequence_arabe` DROP COLUMN `justification`",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
$cols = array_column(db_all("SHOW COLUMNS FROM composer_sequence_arabe"), 'Field');
foreach (['etat_composition', 'justification'] as $c) {
    echo "$c : " . (in_array($c, $cols, true) ? "TOUJOURS PRÉSENTE" : "supprimée") . "\n";
}
echo "note : " . (in_array('note', $cols, true) ? "conservée (attendu)" : "MANQUANTE — anormal") . "\n";

echo "\nMigration v48 terminée.\n";
