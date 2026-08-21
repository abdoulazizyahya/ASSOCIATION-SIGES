<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v30 (mysqli procédural, idempotent)
//  php bd/run_migration_v30.php   (ou via navigateur)
//  Table pdf_couleur (couleurs personnalisables du bulletin PDF).
//  Voir bd/migration_v30.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v30 — jaynitaare_v2 (mysqli) ===\n\n";

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
    "Table pdf_couleur",
    "CREATE TABLE IF NOT EXISTS `pdf_couleur` (
        `cle`     varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
        `libelle` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `r`       tinyint unsigned NOT NULL,
        `g`       tinyint unsigned NOT NULL,
        `b`       tinyint unsigned NOT NULL,
        PRIMARY KEY (`cle`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$defauts = [
    ['groupe_competence', 'En-tête des groupes de compétences', 45, 231, 218],
    ['ligne_alternee', 'Lignes alternées (tableau de notes)', 227, 227, 227],
    ['ligne_rayee', 'Rayures de lignes (bulletin trimestriel FR)', 249, 249, 249],
    ['entete_section', 'En-têtes de section (Discipline/Travail/Profil, FR trim.)', 65, 165, 165],
    ['cellule_resultat', 'Cellules de résultat (Moyenne/Rang/Appréciation)', 228, 228, 228],
    ['entete_bleu', 'En-têtes (bulletin annuel + bulletins arabes)', 146, 220, 255],
];
foreach ($defauts as [$cle, $libelle, $r, $g, $b]) {
    run(
        "Couleur par défaut : $cle",
        "INSERT IGNORE INTO `pdf_couleur` (`cle`,`libelle`,`r`,`g`,`b`) VALUES ('" .
            addslashes($cle) . "','" . addslashes($libelle) . "',$r,$g,$b)"
    );
}

echo "\n=== Vérification ===\n";
$nb = db_val("SELECT COUNT(*) FROM pdf_couleur");
echo "  $nb couleur(s) configurée(s) dans pdf_couleur (6 attendues).\n";

echo "\nMigration v30 terminée.\n";
