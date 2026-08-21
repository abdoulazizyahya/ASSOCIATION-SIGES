<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v31 (mysqli procédural, idempotent)
//  php bd/run_migration_v31.php   (ou via navigateur)
//  Absorption de etablissement_arabe (en-tête officiel bilingue FR/AR,
//  1 seule ligne) dans etablissement (14 nouvelles colonnes), puis
//  suppression de etablissement_arabe.
//  Voir bd/migration_v31.sql pour le détail et le raisonnement.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v31 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1. Nouvelles colonnes sur etablissement (1060 = Duplicate column name,
//    pour un ré-exécution sans effet si déjà migrée)
$colonnes = [
    ['republique_fr',     'varchar(50)',  'lieu_etab'],
    ['devise_fr',         'varchar(50)',  'republique_fr'],
    ['ministere_fr',      'varchar(50)',  'devise_fr'],
    ['delegation_reg_fr', 'varchar(50)',  'ministere_fr'],
    ['delegation_dep_fr', 'varchar(50)',  'delegation_reg_fr'],
    ['arrondissement_fr', 'varchar(50)',  'delegation_dep_fr'],
    ['ecole_fr',          'varchar(250)', 'arrondissement_fr'],
    ['republique_ar',     'varchar(50)',  'ecole_fr'],
    ['devise_ar',         'varchar(50)',  'republique_ar'],
    ['ministere_ar',      'varchar(50)',  'devise_ar'],
    ['delegation_reg_ar', 'varchar(50)',  'ministere_ar'],
    ['delegation_dep_ar', 'varchar(50)',  'delegation_reg_ar'],
    ['arrondissement_ar', 'varchar(50)',  'delegation_dep_ar'],
    ['ecole_ar',          'varchar(250)', 'arrondissement_ar'],
];
foreach ($colonnes as [$nom, $type, $apres]) {
    run(
        "Colonne etablissement.$nom",
        "ALTER TABLE `etablissement` ADD COLUMN `$nom` $type COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `$apres`",
        [1060]
    );
}

// 2. Reprise des données — sans effet si etablissement_arabe a déjà été
//    supprimée (1146 = table inconnue), ce qui rend l'étape idempotente.
run(
    "Reprise de l'en-tête bilingue (etablissement_arabe -> etablissement)",
    "UPDATE `etablissement` e, `etablissement_arabe` a
     SET e.republique_fr = a.republique_fr, e.devise_fr = a.devise_fr,
         e.ministere_fr = a.ministere_fr, e.delegation_reg_fr = a.delegation_reg_fr,
         e.delegation_dep_fr = a.delegation_dep_fr, e.arrondissement_fr = a.arrondissement_fr,
         e.ecole_fr = a.ecole_fr, e.republique_ar = a.republique_ar,
         e.devise_ar = a.devise_ar, e.ministere_ar = a.ministere_ar,
         e.delegation_reg_ar = a.delegation_reg_ar, e.delegation_dep_ar = a.delegation_dep_ar,
         e.arrondissement_ar = a.arrondissement_ar, e.ecole_ar = a.ecole_ar",
    [1146]
);

run("Suppression etablissement_arabe", "DROP TABLE IF EXISTS `etablissement_arabe`");

echo "\n=== Vérification ===\n";
$cols = db_all("SHOW COLUMNS FROM etablissement");
$noms = array_column($cols, 'Field');
$attendues = ['republique_fr', 'devise_fr', 'ministere_fr', 'delegation_reg_fr', 'delegation_dep_fr',
              'arrondissement_fr', 'ecole_fr', 'republique_ar', 'devise_ar', 'ministere_ar',
              'delegation_reg_ar', 'delegation_dep_ar', 'arrondissement_ar', 'ecole_ar'];
$manquantes = array_diff($attendues, $noms);
echo empty($manquantes)
    ? "  Les 14 colonnes bilingues sont présentes sur etablissement.\n"
    : "  ECHEC : colonnes manquantes -> " . implode(', ', $manquantes) . "\n";

$ecole_ar = db_val("SELECT ecole_ar FROM etablissement LIMIT 1");
echo $ecole_ar ? "  ecole_ar repris : $ecole_ar\n" : "  ATTENTION : ecole_ar vide après reprise.\n";

$table_restante = db_all(
    "SELECT TABLE_NAME FROM information_schema.tables
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'etablissement_arabe'"
);
echo empty($table_restante)
    ? "  etablissement_arabe a bien été supprimée.\n"
    : "  ATTENTION : etablissement_arabe encore présente !\n";

echo "\nMigration v31 terminée.\n";
