<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v25 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v25.php
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v25 — ABZ_MBE (mysqli) ===\n\n";

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

// 1062 = Duplicate entry
run("signature_titulaire.secretaire",
    "INSERT INTO `signature_titulaire` (`code`, `libelle`, `role_gestion`)
     VALUES ('secretaire', 'Secrétaire', 'SECRETAIRE')",
    [1062]);

echo "\n=== Vérification ===\n";
print_r(db_all("SELECT * FROM signature_titulaire"));

echo "\n✅ Migration v25 terminée. Vous pouvez supprimer ce fichier après usage.\n";
