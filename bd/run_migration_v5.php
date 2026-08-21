<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v5 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v5.php
//
//  ⚠️ Touche la table `eleve` (227 fiches réelles) — sauvegardée au
//  préalable via mysqldump (bd/backup_eleve_avant_migration_v5_*.sql).
//  Voir bd/migration_v5.sql pour le détail des 3 changements.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v5 — jaynitaare_v2 (mysqli) ===\n\n";

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

$nb_avant = db_val("SELECT COUNT(*) FROM eleve");
echo "Lignes eleve avant migration : $nb_avant\n\n";

run("id_eleve en première position",
    "ALTER TABLE `eleve` MODIFY COLUMN `id_eleve` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST");

// 1060 = colonne déjà existante (déjà exécutée précédemment)
// INT signé (pas UNSIGNED) : doit correspondre exactement au type de
// arrondissement.code_arrond, sinon la FK suivante échoue (erreur 3780).
run("Colonne `eleve.id_arrondissement`",
    "ALTER TABLE `eleve` ADD COLUMN `id_arrondissement` INT NULL AFTER `arrondissement_elv`",
    [1060]);

// 1826 = contrainte FK déjà existante
run("FK `fk_eleve_arrondissement`",
    "ALTER TABLE `eleve` ADD CONSTRAINT `fk_eleve_arrondissement`
     FOREIGN KEY (`id_arrondissement`) REFERENCES `arrondissement` (`code_arrond`)
     ON DELETE SET NULL ON UPDATE CASCADE",
    [1826]);

// 1091 = clé étrangère/colonne introuvable (déjà supprimée précédemment)
run("Suppression FK `eleve_ibfk_1`",
    "ALTER TABLE `eleve` DROP FOREIGN KEY `eleve_ibfk_1`",
    [1091]);
run("Suppression colonne `Departement_elv`",
    "ALTER TABLE `eleve` DROP COLUMN `Departement_elv`",
    [1091]);

echo "\n=== Vérification ===\n";
$nb_apres = db_val("SELECT COUNT(*) FROM eleve");
echo "Lignes eleve après migration : $nb_apres " . ($nb_apres == $nb_avant ? '(inchangé, OK)' : '(⚠️ DIFFÉRENT !)') . "\n";
$cols = db_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='eleve' ORDER BY ORDINAL_POSITION");
echo "Ordre des colonnes : " . implode(', ', array_column($cols, 'COLUMN_NAME')) . "\n";
$a_departement = in_array('Departement_elv', array_column($cols, 'COLUMN_NAME'), true);
echo "Departement_elv encore présente : " . ($a_departement ? 'OUI — ÉCHEC' : 'non, supprimée (OK)') . "\n";

echo "\n✅ Migration v5 terminée.\n";
