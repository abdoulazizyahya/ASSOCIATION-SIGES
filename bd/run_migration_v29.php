<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v29 (mysqli procédural, idempotent)
//  php bd/run_migration_v29.php   (ou via navigateur)
//  Optimisation capacité/mémoire : suppression d'index redondants +
//  correction du type de moyenne_trimestre(_arabe).moy. Voir
//  bd/migration_v29.sql pour le détail et le raisonnement.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v29 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1091 = "can't DROP KEY; check that it exists" -> déjà migré, idempotent
$ignoreDropKey = [1091];

echo "-- A. Index redondants --\n";
run("absence.mat_elv (doublon de id_trim)", "ALTER TABLE `absence` DROP KEY `mat_elv`", $ignoreDropKey);
run("moyenne_sequence_arabe.mat_elv (doublon de id_seq)", "ALTER TABLE `moyenne_sequence_arabe` DROP KEY `mat_elv`", $ignoreDropKey);
run("moyenne_annuelle.classe (couvert par mat_elv)", "ALTER TABLE `moyenne_annuelle` DROP KEY `classe`", $ignoreDropKey);
run("discipline.IDClasses (couvert par IDClass_comp_annee)", "ALTER TABLE `discipline` DROP KEY `IDClasses`", $ignoreDropKey);
run("enseignat_classe.matricule_ens (couvert par matricule_ens_Classe_Annee)", "ALTER TABLE `enseignat_classe` DROP KEY `matricule_ens`", $ignoreDropKey);
run("enseignat_classe_arabe.matricule_ens (couvert par matricule_ens_Classe_Annee)", "ALTER TABLE `enseignat_classe_arabe` DROP KEY `matricule_ens`", $ignoreDropKey);
run("inscrire.idx_inscrire_eleve (couvert par uk_inscrire_eleve_annee)", "ALTER TABLE `inscrire` DROP KEY `idx_inscrire_eleve`", $ignoreDropKey);
run("inscrire.IDClasses (couvert par Mat_elv_Classe_annee)", "ALTER TABLE `inscrire` DROP KEY `IDClasses`", $ignoreDropKey);
run("exclusion.mat_elv_2 (UNIQUE bugué + redondant)", "ALTER TABLE `exclusion` DROP KEY `mat_elv_2`", $ignoreDropKey);

echo "\n-- B. Type numérique moyenne_trimestre(_arabe).moy --\n";
run("Purge des 6 lignes moy='' historiques (moyenne_trimestre_arabe)", "DELETE FROM `moyenne_trimestre_arabe` WHERE `moy` = ''");
run("moyenne_trimestre.moy -> decimal(4,2)", "ALTER TABLE `moyenne_trimestre` MODIFY COLUMN `moy` decimal(4,2) NOT NULL");
run("moyenne_trimestre_arabe.moy -> decimal(4,2)", "ALTER TABLE `moyenne_trimestre_arabe` MODIFY COLUMN `moy` decimal(4,2) NOT NULL");

echo "\n=== Vérification ===\n";
$idx = db_all(
    "SELECT TABLE_NAME, INDEX_NAME FROM information_schema.statistics
     WHERE TABLE_SCHEMA = DATABASE()
       AND (TABLE_NAME,INDEX_NAME) IN (
         ('absence','mat_elv'), ('moyenne_sequence_arabe','mat_elv'), ('moyenne_annuelle','classe'),
         ('discipline','IDClasses'), ('enseignat_classe','matricule_ens'), ('enseignat_classe_arabe','matricule_ens'),
         ('inscrire','idx_inscrire_eleve'), ('inscrire','IDClasses'), ('exclusion','mat_elv_2')
       )"
);
echo empty($idx) ? "  Les 9 index redondants sont bien supprimés.\n"
                 : "  ATTENTION : encore présents -> " . implode(', ', array_map(fn($r) => $r['TABLE_NAME'].'.'.$r['INDEX_NAME'], $idx)) . "\n";

$type_mt = db_one("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='moyenne_trimestre' AND column_name='moy'");
$type_mta = db_one("SELECT DATA_TYPE FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='moyenne_trimestre_arabe' AND column_name='moy'");
echo "  moyenne_trimestre.moy : " . ($type_mt['DATA_TYPE'] ?? '?') . "\n";
echo "  moyenne_trimestre_arabe.moy : " . ($type_mta['DATA_TYPE'] ?? '?') . "\n";

$nb_mt  = db_val("SELECT COUNT(*) FROM moyenne_trimestre");
$nb_mta = db_val("SELECT COUNT(*) FROM moyenne_trimestre_arabe");
echo "  Lignes moyenne_trimestre : $nb_mt / moyenne_trimestre_arabe : $nb_mta (434/484 attendues, -6 côté arabe).\n";

echo "\nMigration v29 terminée.\n";
