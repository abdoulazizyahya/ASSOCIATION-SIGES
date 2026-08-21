<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v2 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v2.php
//
//  Ajoute `groupe_competence.ordre_affichage` (+ réamorçage des 12 lignes
//  existantes) et `discipline.actif`. Aucune autre donnée n'est modifiée.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';   // fournit $link + helpers db_*

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v2 — jaynitaare_v2 (mysqli) ===\n\n";

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
run("Colonne `groupe_competence.ordre_affichage`",
    "ALTER TABLE `groupe_competence` ADD COLUMN `ordre_affichage` INT NOT NULL DEFAULT 0 AFTER `langue`",
    [1060]);

run("Réamorçage des 12 groupes (ordre miroir Fr/An 1..6)",
    "UPDATE `groupe_competence` SET `ordre_affichage` = CASE `id_groupe_comp`
        WHEN 1  THEN 1  WHEN 2  THEN 2  WHEN 3  THEN 3
        WHEN 4  THEN 4  WHEN 5  THEN 5  WHEN 6  THEN 6
        WHEN 7  THEN 1  WHEN 8  THEN 2  WHEN 9  THEN 3
        WHEN 10 THEN 4  WHEN 11 THEN 5  WHEN 12 THEN 6
        ELSE `id_groupe_comp`
     END");

run("Colonne `discipline.actif`",
    "ALTER TABLE `discipline` ADD COLUMN `actif` TINYINT(1) NOT NULL DEFAULT 1 AFTER `total_points`",
    [1060]);

echo "\n=== Vérification ===\n";
foreach (db_all("SELECT id_groupe_comp, langue, ordre_affichage FROM groupe_competence ORDER BY ordre_affichage, langue") as $r) {
    echo "  groupe {$r['id_groupe_comp']} ({$r['langue']}) -> ordre {$r['ordre_affichage']}\n";
}
$col = db_val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='discipline' AND COLUMN_NAME='actif'");
echo "Colonne discipline.actif presente : " . ($col ? 'oui' : 'NON — ÉCHEC') . "\n";
$nb_inactifs = (int) db_val("SELECT COUNT(*) FROM discipline WHERE actif=0");
echo "Lignes discipline.actif=0 : $nb_inactifs (0 attendu, toutes actives par défaut)\n";

echo "\n✅ Migration v2 terminée.\n";
