<?php
// =====================================================================
//  ABZ_MBE — Runner de la migration v16 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/ABZ_MBE/bd/run_migration_v16.php
//
//  Ajoute la portée d'un frais exigible (obligation_frais.portee) :
//  'etablissement' (tous niveaux), 'cycle' (via id_cycle, texte libre
//  '1er Cycle'/'2nd Cycle' comme niveau.id_cycle), ou 'niveau' (défaut,
//  comportement d'origine — code_niveau devient nullable en conséquence).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v16 — ABZ_MBE (mysqli) ===\n\n";

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
run("obligation_frais.portee",
    "ALTER TABLE `obligation_frais` ADD COLUMN `portee` ENUM('etablissement','cycle','niveau') NOT NULL DEFAULT 'niveau' AFTER `libelle`",
    [1060]);
run("obligation_frais.id_cycle",
    "ALTER TABLE `obligation_frais` ADD COLUMN `id_cycle` VARCHAR(20) NULL DEFAULT NULL AFTER `code_niveau`",
    [1060]);
run("obligation_frais.code_niveau nullable",
    "ALTER TABLE `obligation_frais` MODIFY COLUMN `code_niveau` VARCHAR(25) NULL DEFAULT NULL");

// 1091 = Can't DROP index ; check that it exists
run("DROP ancien index uk_obligation",
    "ALTER TABLE `obligation_frais` DROP INDEX `uk_obligation`",
    [1091]);
// 1061 = Duplicate key name (deja recree lors d'un run precedent)
run("Nouvel index uk_obligation (portee incluse)",
    "ALTER TABLE `obligation_frais` ADD UNIQUE KEY `uk_obligation` (`libelle`, `portee`, `code_niveau`, `id_cycle`, `id_annee`)",
    [1061]);

echo "\n=== Vérification ===\n";
foreach (db_all("SHOW COLUMNS FROM obligation_frais") as $c) {
    echo "  {$c['Field']}  {$c['Type']}\n";
}

echo "\n✅ Migration v16 terminée. Vous pouvez supprimer ce fichier après usage.\n";
