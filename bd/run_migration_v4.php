<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v4 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v4.php
//
//  Crée `arrondissement` (vide — voir bd/migration_v4.sql). Aucune donnée
//  existante n'est modifiée.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v4 — jaynitaare_v2 (mysqli) ===\n\n";

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

run("Table `arrondissement`",
    "CREATE TABLE IF NOT EXISTS `arrondissement` (
        `code_arrond`     INT NOT NULL AUTO_INCREMENT,
        `intitule_arrond` VARCHAR(100) NOT NULL,
        `code_depart`     INT NOT NULL,
        PRIMARY KEY (`code_arrond`),
        UNIQUE KEY `uk_arrond_depart` (`code_depart`, `intitule_arrond`),
        KEY `code_depart` (`code_depart`),
        CONSTRAINT `arrondissement_ibfk_1` FOREIGN KEY (`code_depart`) REFERENCES `departement` (`code_depart`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "\n=== Vérification ===\n";
$existe = db_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='arrondissement'");
echo "Table présente : " . ($existe ? 'oui' : 'NON — ÉCHEC') . "\n";
echo "Lignes actuelles : " . db_val("SELECT COUNT(*) FROM arrondissement") . " (0 attendu — à importer via bd/sync_arrondissements.php)\n";

echo "\n✅ Migration v4 terminée.\n";
