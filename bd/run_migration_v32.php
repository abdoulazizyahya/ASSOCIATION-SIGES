<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v32 (mysqli procédural, idempotent)
//  php bd/run_migration_v32.php   (ou via navigateur)
//  Nouvelle couleur "colonne_annuelle" (bulletin annuel arabe).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v32 — jaynitaare_v2 (mysqli) ===\n\n";

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
    "Couleur colonne_annuelle",
    "INSERT IGNORE INTO `pdf_couleur` (`cle`,`libelle`,`r`,`g`,`b`) VALUES ('colonne_annuelle','Colonne ANNUELLE (bulletin annuel arabe)',250,214,165)"
);

echo "\n=== Vérification ===\n";
$nb = db_val("SELECT COUNT(*) FROM pdf_couleur");
echo "  $nb couleur(s) configurée(s) dans pdf_couleur (7 attendues).\n";

echo "\nMigration v32 terminée.\n";
