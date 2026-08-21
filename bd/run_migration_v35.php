<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v35 (mysqli procédural, idempotent)
//  php bd/run_migration_v35.php   (ou via navigateur)
//  Bulletin de paie au format CNPS (modèle fourni : salaire.pdf, style
//  CAMTEL) — champs administratifs sur enseignant + détail par rubrique
//  sur ligne_bulletin_paie. Voir bd/migration_v35.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v35 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1060 = colonne déjà présente (ré-exécution sans effet).
$DEJA_LA = [1060];

run("Colonne enseignant.matricule_cnps", "ALTER TABLE `enseignant` ADD COLUMN `matricule_cnps` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mat_ens`", $DEJA_LA);
run("Colonne enseignant.nb_enfants",     "ALTER TABLE `enseignant` ADD COLUMN `nb_enfants` tinyint unsigned DEFAULT 0 AFTER `situation_ens`", $DEJA_LA);
run("Colonne enseignant.nb_pers_charge", "ALTER TABLE `enseignant` ADD COLUMN `nb_pers_charge` tinyint unsigned DEFAULT 0 AFTER `nb_enfants`", $DEJA_LA);
run("Colonne enseignant.indice_grille",  "ALTER TABLE `enseignant` ADD COLUMN `indice_grille` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `id_grade`", $DEJA_LA);
run("Colonne enseignant.nom_banque",     "ALTER TABLE `enseignant` ADD COLUMN `nom_banque` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mode_paiement`", $DEJA_LA);

run("Colonne ligne_bulletin_paie.code_rubrique", "ALTER TABLE `ligne_bulletin_paie` ADD COLUMN `code_rubrique` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `type_ligne`", $DEJA_LA);
run("Colonne ligne_bulletin_paie.nb",            "ALTER TABLE `ligne_bulletin_paie` ADD COLUMN `nb` decimal(10,2) DEFAULT NULL AFTER `libelle`", $DEJA_LA);
run("Colonne ligne_bulletin_paie.base",          "ALTER TABLE `ligne_bulletin_paie` ADD COLUMN `base` decimal(12,2) DEFAULT NULL AFTER `montant`", $DEJA_LA);
run("Colonne ligne_bulletin_paie.taux_pct",      "ALTER TABLE `ligne_bulletin_paie` ADD COLUMN `taux_pct` decimal(6,2) DEFAULT NULL AFTER `base`", $DEJA_LA);

echo "\n=== Vérification ===\n";
$cols_ens = array_column(db_all("SHOW COLUMNS FROM enseignant"), 'Field');
foreach (['matricule_cnps', 'nb_enfants', 'nb_pers_charge', 'indice_grille', 'nom_banque'] as $c) {
    echo in_array($c, $cols_ens, true) ? "  enseignant.$c présente.\n" : "  ECHEC : enseignant.$c absente !\n";
}
$cols_ligne = array_column(db_all("SHOW COLUMNS FROM ligne_bulletin_paie"), 'Field');
foreach (['code_rubrique', 'nb', 'base', 'taux_pct'] as $c) {
    echo in_array($c, $cols_ligne, true) ? "  ligne_bulletin_paie.$c présente.\n" : "  ECHEC : ligne_bulletin_paie.$c absente !\n";
}

echo "\nMigration v35 terminée.\n";
