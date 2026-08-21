<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v28 (mysqli procédural, idempotent)
//  php bd/run_migration_v28.php   (ou via navigateur)
//  Ajoute la PRIMARY KEY manquante sur obligation.id_obligation.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v28 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1091 = "can't DROP KEY; check that it exists" -> déjà migré
run(
    "PRIMARY KEY sur obligation.id_obligation",
    "ALTER TABLE `obligation` DROP KEY `id_obligation`, ADD PRIMARY KEY (`id_obligation`)",
    [1091, 1068]
);

echo "\n=== Vérification ===\n";
$idx = db_all("SHOW KEYS FROM obligation WHERE Key_name='PRIMARY'");
echo !empty($idx) ? "  PRIMARY KEY presente sur id_obligation.\n" : "  ECHEC : pas de PRIMARY KEY.\n";
$nb = db_val("SELECT COUNT(*) FROM obligation");
echo "  Lignes obligation : $nb (doit rester inchangé).\n";

echo "\nMigration v28 terminée.\n";
