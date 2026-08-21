<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v27 (mysqli procédural, idempotent)
//  php bd/run_migration_v27.php   (ou via navigateur)
//  Nettoyage BD : absorption de eleve_arabe dans eleve.Nom_arabe_elv,
//  suppression de document et note_trimestrielle (tables mortes).
//  Voir bd/migration_v27.sql pour le détail et le raisonnement.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v27 — jaynitaare_v2 (mysqli) ===\n\n";

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

// Colonne déjà présente ? (1060 = Duplicate column name)
run(
    "Colonne eleve.Nom_arabe_elv",
    "ALTER TABLE `eleve` ADD COLUMN `Nom_arabe_elv` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `Nom_elv`",
    [1060]
);

// Reprise des données — sans effet si eleve_arabe a déjà été supprimée
// (1146 = table inconnue), ce qui rend l'étape idempotente elle aussi.
run(
    "Reprise des noms arabes (eleve_arabe -> eleve)",
    "UPDATE `eleve` e
     JOIN (SELECT `Mat_elv`, MIN(`Nom_elv`) AS nom_ar FROM `eleve_arabe` GROUP BY `Mat_elv`) x
       ON x.`Mat_elv` = e.`Mat_elv`
     SET e.`Nom_arabe_elv` = x.nom_ar",
    [1146]
);

run("Suppression eleve_arabe", "DROP TABLE IF EXISTS `eleve_arabe`");
run("Suppression document", "DROP TABLE IF EXISTS `document`");
run("Suppression note_trimestrielle", "DROP TABLE IF EXISTS `note_trimestrielle`");

echo "\n=== Vérification ===\n";
$cols = db_all("SHOW COLUMNS FROM eleve");
$noms = array_column($cols, 'Field');
echo in_array('Nom_arabe_elv', $noms, true) ? "  Colonne Nom_arabe_elv presente.\n" : "  ECHEC : colonne Nom_arabe_elv absente !\n";

$nb_total    = db_val("SELECT COUNT(*) FROM eleve");
$nb_avec_ar  = db_val("SELECT COUNT(*) FROM eleve WHERE Nom_arabe_elv IS NOT NULL AND Nom_arabe_elv <> ''");
echo "  Élèves : $nb_total, dont $nb_avec_ar avec un nom arabe repris.\n";

$tables_restantes = db_all(
    "SELECT TABLE_NAME FROM information_schema.tables
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('eleve_arabe','document','note_trimestrielle')"
);
echo empty($tables_restantes)
    ? "  Les 3 tables mortes ont bien été supprimées.\n"
    : "  ATTENTION : encore présentes -> " . implode(', ', array_column($tables_restantes, 'TABLE_NAME')) . "\n";

echo "\nMigration v27 terminée.\n";
