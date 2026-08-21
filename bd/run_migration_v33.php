<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v33 (mysqli procédural, idempotent)
//  php bd/run_migration_v33.php   (ou via navigateur)
//  Module Gestion des dépenses : categorie_depense + depense.
//  Voir bd/migration_v33.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v33 — jaynitaare_v2 (mysqli) ===\n\n";

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
    "Table categorie_depense",
    "CREATE TABLE IF NOT EXISTS `categorie_depense` (
        `id_categorie` int NOT NULL AUTO_INCREMENT,
        `libelle`      varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `description`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        PRIMARY KEY (`id_categorie`),
        UNIQUE KEY `uk_categorie_depense_libelle` (`libelle`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table depense",
    "CREATE TABLE IF NOT EXISTS `depense` (
        `id_depense`    int NOT NULL AUTO_INCREMENT,
        `id_categorie`  int NOT NULL,
        `libelle`       varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
        `montant`       decimal(12,2) NOT NULL,
        `date_depense`  date NOT NULL,
        `val_annee`     varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `beneficiaire`  varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `observation`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `cree_le`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_depense`),
        KEY `id_categorie` (`id_categorie`),
        KEY `val_annee` (`val_annee`),
        KEY `id_utilisateur` (`id_utilisateur`),
        KEY `date_depense` (`date_depense`),
        CONSTRAINT `fk_depense_categorie` FOREIGN KEY (`id_categorie`) REFERENCES `categorie_depense` (`id_categorie`) ON DELETE RESTRICT ON UPDATE CASCADE,
        CONSTRAINT `fk_depense_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_depense_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$categories = [
    ['Salaires', 'Salaires et primes du personnel'],
    ['Fournitures scolaires', 'Matériel et fournitures pédagogiques'],
    ['Entretien & réparations', 'Maintenance des locaux et équipements'],
    ['Eau & Électricité', "Factures d'eau et d'électricité"],
    ['Transport', 'Frais de transport et carburant'],
    ['Administration', 'Frais administratifs divers'],
    ['Autres', 'Dépenses non classées ailleurs'],
];
foreach ($categories as [$lib, $desc]) {
    run(
        "Catégorie par défaut : $lib",
        "INSERT IGNORE INTO `categorie_depense` (`libelle`,`description`) VALUES ('" . addslashes($lib) . "','" . addslashes($desc) . "')"
    );
}

echo "\n=== Vérification ===\n";
$nb_cat = db_val("SELECT COUNT(*) FROM categorie_depense");
echo "  $nb_cat catégorie(s) de dépense (7 attendues).\n";
$nb_dep = db_val("SELECT COUNT(*) FROM depense");
echo "  $nb_dep dépense(s) enregistrée(s) (0 attendu, table neuve).\n";

echo "\nMigration v33 terminée.\n";
