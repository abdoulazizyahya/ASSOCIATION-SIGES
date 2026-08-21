<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v3 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v3.php
//
//  Ajoute `niveau.actif` (DEFAULT 1 — tous les niveaux existants restent
//  actifs). Aucune autre donnée n'est modifiée.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v3 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1060 = Duplicate column name (déjà exécutée précédemment)
run("Colonne `niveau.actif`",
    "ALTER TABLE `niveau` ADD COLUMN `actif` TINYINT(1) NOT NULL DEFAULT 1 AFTER `OrdreNiveau`",
    [1060]);

echo "\n=== Vérification ===\n";
foreach (db_all("SELECT LibelleNiveau, OrdreNiveau, actif FROM niveau ORDER BY OrdreNiveau") as $r) {
    echo "  {$r['LibelleNiveau']} (ordre {$r['OrdreNiveau']}) -> actif={$r['actif']}\n";
}

echo "\n✅ Migration v3 terminée.\n";
