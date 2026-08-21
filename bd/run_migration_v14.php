<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v14 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v14.php
//
//  Ajoute obligation_frais.mode_paiement ('cash' ou 'operateur') : certains
//  frais (APEE, Livret médical...) se paient en espèces, d'autres
//  (Inscription, Examen...) doivent passer par un opérateur mobile/banque.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v14 — ABZ_MBE (mysqli) ===\n\n";

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

// 1060 = Duplicate column name → colonne déjà présente, on ignore.
run("obligation_frais.mode_paiement",
    "ALTER TABLE `obligation_frais` ADD COLUMN `mode_paiement` ENUM('cash','operateur') NOT NULL DEFAULT 'cash' AFTER `montant`",
    [1060]);

echo "\n✅ Migration v14 terminée. Vous pouvez supprimer ce fichier après usage.\n";
