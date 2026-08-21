<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v26 (mysqli procédural, idempotent)
//  php bd/run_migration_v26.php   (ou via navigateur)
//  Finances : traçabilité de l'agent qui encaisse (voir migration_v26.sql).
//  Pas de mode_paiement : établissement 100% cash, pas d'opérateurs à
//  distinguer (demande explicite de l'utilisateur).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v26 — jaynitaare_v2 (mysqli) ===\n\n";

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

run(
    "Colonne id_utilisateur",
    "ALTER TABLE `paiement_frais` ADD COLUMN `id_utilisateur` INT NULL AFTER `ref_paiement`",
    [1060]
);

run(
    "Index id_utilisateur",
    "ALTER TABLE `paiement_frais` ADD KEY `id_utilisateur` (`id_utilisateur`)",
    [1061]
);

run(
    "FK id_utilisateur -> user(id_user)",
    "ALTER TABLE `paiement_frais`
       ADD CONSTRAINT `fk_paiement_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE",
    [1826, 1005]
);

echo "\n=== Vérification ===\n";
$cols = db_all("SHOW COLUMNS FROM paiement_frais");
$noms = array_column($cols, 'Field');
echo in_array('id_utilisateur', $noms, true) ? "  Colonne id_utilisateur presente.\n" : "  ECHEC : colonne id_utilisateur absente !\n";
$nb    = db_val("SELECT COUNT(*) FROM paiement_frais");
$total = db_val("SELECT SUM(montant_paiement) FROM paiement_frais");
echo "  Lignes paiement_frais : $nb (somme $total F) - doit rester inchangé.\n";

echo "\nMigration v26 terminée.\n";
