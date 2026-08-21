<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v43 (mysqli procédural, idempotent)
//  php bd/run_migration_v43.php   (ou via navigateur)
//  Mode de paiement d'un versement (Espèces par défaut, Orange Money, MTN
//  Mobile Money, Virement bancaire, Autre). Voir bd/migration_v43.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v43 — jaynitaare_v2 (mysqli) ===\n\n";

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
    "paiement_frais.mode_paiement",
    "ALTER TABLE `paiement_frais` ADD COLUMN `mode_paiement` ENUM('ESPECES','ORANGE_MONEY','MOMO','BANQUE','AUTRE') NOT NULL DEFAULT 'ESPECES' AFTER `ref_paiement`",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
$cols = db_all("SHOW COLUMNS FROM paiement_frais WHERE Field = 'mode_paiement'");
echo "  mode_paiement : " . ($cols ? 'présente' : 'MANQUANTE — ÉCHEC') . "\n";

echo "\nMigration v43 terminée.\n";
