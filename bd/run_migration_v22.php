<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v22 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v22.php
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v22 — ABZ_MBE (mysqli) ===\n\n";

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
run("enseignant.signature",
    "ALTER TABLE `enseignant` ADD COLUMN `signature` VARCHAR(255) NULL DEFAULT NULL AFTER `matiere_enseignee`",
    [1060]);

run("chef_etablissement → role_gestion PROVISEUR",
    "UPDATE `signature_titulaire` SET `role_gestion` = 'PROVISEUR' WHERE `code` = 'chef_etablissement'");

// 1062 = Duplicate entry (déjà seedé)
run("Seed censeur",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('censeur', 'Censeur', 'CENSEUR')",
    [1062]);
run("Seed surveillant_general",
    "INSERT INTO signature_titulaire (code, libelle, role_gestion) VALUES ('surveillant_general', 'Surveillant Général', 'SG')",
    [1062]);

echo "\n=== Vérification ===\n";
print_r(db_all("SELECT * FROM signature_titulaire"));

echo "\n✅ Migration v22 terminée. Vous pouvez supprimer ce fichier après usage.\n";
