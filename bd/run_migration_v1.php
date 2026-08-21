<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v1 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v1.php
//
//  Crée `groupe_competence_niveau` (association + activation d'un groupe
//  de compétences par niveau). Aucune donnée n'est modifiée dans les
//  tables existantes (héritées du legacy, jamais altérées par ce projet).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';   // fournit $link + helpers db_*

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v1 — jaynitaare_v2 (mysqli) ===\n\n";

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

run("Table `groupe_competence_niveau`",
    "CREATE TABLE IF NOT EXISTS `groupe_competence_niveau` (
        `code_niveau`    VARCHAR(10) NOT NULL,
        `id_groupe_comp` INT NOT NULL,
        `actif`          TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`code_niveau`, `id_groupe_comp`),
        KEY `id_groupe_comp` (`id_groupe_comp`),
        CONSTRAINT `fk_gcn_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_gcn_groupe` FOREIGN KEY (`id_groupe_comp`) REFERENCES `groupe_competence` (`id_groupe_comp`) ON DELETE CASCADE ON UPDATE CASCADE
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

echo "\n=== Vérification ===\n";
echo "Table présente : " . (db_val("SHOW TABLES LIKE 'groupe_competence_niveau'") ? 'oui' : 'NON — ÉCHEC') . "\n";
echo "Lignes actuelles : " . db_val("SELECT COUNT(*) FROM groupe_competence_niveau") . " (0 attendu, à configurer via l'écran Pédagogie)\n";

echo "\n✅ Migration v1 terminée. Vous pouvez supprimer ce fichier après usage.\n";
