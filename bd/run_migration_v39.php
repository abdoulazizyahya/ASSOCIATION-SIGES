<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v39 (mysqli procédural, idempotent)
//  php bd/run_migration_v39.php   (ou via navigateur)
//  "Cas social" (case à cocher + % de réduction) sur la fiche élève.
//  Voir bd/migration_v39.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v39 — jaynitaare_v2 (mysqli) ===\n\n";

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

run(
    "info_supplementaires.cas_social",
    "ALTER TABLE `info_supplementaires` ADD COLUMN `cas_social` TINYINT(1) NOT NULL DEFAULT 0 AFTER `indigent`",
    $DEJA_LA
);
run(
    "info_supplementaires.pourcentage_reduction",
    "ALTER TABLE `info_supplementaires` ADD COLUMN `pourcentage_reduction` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `cas_social`",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
$cols = db_all("SHOW COLUMNS FROM info_supplementaires WHERE Field IN ('cas_social','pourcentage_reduction')");
foreach (['cas_social', 'pourcentage_reduction'] as $c) {
    $ok = false;
    foreach ($cols as $col) { if ($col['Field'] === $c) $ok = true; }
    echo "  $c : " . ($ok ? 'présente' : 'MANQUANTE — ÉCHEC') . "\n";
}

echo "\nMigration v39 terminée.\n";
