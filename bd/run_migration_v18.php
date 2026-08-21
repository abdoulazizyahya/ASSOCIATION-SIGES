<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v18 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v18.php
//
//  Ajoute etablissement.immatriculation et fixe sa valeur.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v18 — ABZ_MBE (mysqli) ===\n\n";

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

// 1060 = Duplicate column name
run("etablissement.immatriculation",
    "ALTER TABLE `etablissement` ADD COLUMN `immatriculation` VARCHAR(50) NULL DEFAULT NULL AFTER `sigle`",
    [1060]);

db_exec("UPDATE etablissement SET immatriculation = ? WHERE id = 1", ['2JH1TEFD110316102']);
echo "✓ Valeur fixée : 2JH1TEFD110316102\n";

echo "\n=== Vérification ===\n";
print_r(db_one("SELECT id, sigle, immatriculation FROM etablissement WHERE id=1"));

echo "\n✅ Migration v18 terminée. Vous pouvez supprimer ce fichier après usage.\n";
