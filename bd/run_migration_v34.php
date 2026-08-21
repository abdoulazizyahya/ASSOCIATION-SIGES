<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v34 (mysqli procédural, idempotent)
//  php bd/run_migration_v34.php   (ou via navigateur)
//  Module Gestion des enseignants (RH complète) + Paie.
//  Voir bd/migration_v34.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v34 — jaynitaare_v2 (mysqli) ===\n\n";

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

// Codes ignorés en cas de ré-exécution : 1060 colonne déjà là, 1061/1826/1005
// contrainte/clé déjà là, 1050 table déjà là.
$DEJA_LA = [1060, 1061, 1826, 1005, 1050];

run(
    "Table grade_enseignant",
    "CREATE TABLE IF NOT EXISTS `grade_enseignant` (
        `code_grade`      varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
        `libelle_grade`   varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `salaire_base`    decimal(12,2) NOT NULL DEFAULT 0,
        `ordre_affichage` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`code_grade`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table indemnite_grade",
    "CREATE TABLE IF NOT EXISTS `indemnite_grade` (
        `id`                int NOT NULL AUTO_INCREMENT,
        `code_grade`        varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
        `libelle_indemnite` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `montant`           decimal(12,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `code_grade` (`code_grade`),
        CONSTRAINT `fk_indemnite_grade` FOREIGN KEY (`code_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table contrat_enseignant",
    "CREATE TABLE IF NOT EXISTS `contrat_enseignant` (
        `id`            int NOT NULL AUTO_INCREMENT,
        `matricule_ens` int NOT NULL,
        `type_contrat`  varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
        `date_debut`    date NOT NULL,
        `date_fin`      date DEFAULT NULL,
        `actif`         tinyint(1) NOT NULL DEFAULT 1,
        `remarques`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `cree_le`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `matricule_ens` (`matricule_ens`),
        CONSTRAINT `fk_contrat_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table conge_enseignant",
    "CREATE TABLE IF NOT EXISTS `conge_enseignant` (
        `id`             int NOT NULL AUTO_INCREMENT,
        `matricule_ens`  int NOT NULL,
        `type_conge`     varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
        `date_debut`     date NOT NULL,
        `date_fin`       date NOT NULL,
        `nb_jours`       int NOT NULL DEFAULT 0,
        `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `statut`         varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Validé',
        `deduit_paie`    tinyint(1) NOT NULL DEFAULT 0,
        `id_utilisateur` int DEFAULT NULL,
        `cree_le`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `matricule_ens` (`matricule_ens`),
        KEY `id_utilisateur` (`id_utilisateur`),
        CONSTRAINT `fk_conge_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_conge_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table avance_salaire",
    "CREATE TABLE IF NOT EXISTS `avance_salaire` (
        `id`             int NOT NULL AUTO_INCREMENT,
        `matricule_ens`  int NOT NULL,
        `montant`        decimal(12,2) NOT NULL,
        `date_avance`    date NOT NULL,
        `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `cree_le`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `matricule_ens` (`matricule_ens`),
        KEY `id_utilisateur` (`id_utilisateur`),
        CONSTRAINT `fk_avance_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_avance_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table periode_paie",
    "CREATE TABLE IF NOT EXISTS `periode_paie` (
        `id`              int NOT NULL AUTO_INCREMENT,
        `mois`            tinyint NOT NULL,
        `annee`           smallint NOT NULL,
        `libelle`         varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
        `statut`          varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Brouillon',
        `cree_le`         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `date_validation` timestamp NULL DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_periode_mois_annee` (`mois`,`annee`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table bulletin_paie",
    "CREATE TABLE IF NOT EXISTS `bulletin_paie` (
        `id`                       int NOT NULL AUTO_INCREMENT,
        `id_periode`               int NOT NULL,
        `matricule_ens`            int NOT NULL,
        `code_grade`               varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `salaire_base`             decimal(12,2) NOT NULL DEFAULT 0,
        `total_indemnites`         decimal(12,2) NOT NULL DEFAULT 0,
        `total_primes`             decimal(12,2) NOT NULL DEFAULT 0,
        `total_retenues`           decimal(12,2) NOT NULL DEFAULT 0,
        `montant_avance_deduite`   decimal(12,2) NOT NULL DEFAULT 0,
        `montant_absence_deduite`  decimal(12,2) NOT NULL DEFAULT 0,
        `jours_absence`            int NOT NULL DEFAULT 0,
        `brut`                     decimal(12,2) NOT NULL DEFAULT 0,
        `net_a_payer`              decimal(12,2) NOT NULL DEFAULT 0,
        `statut`                   varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Généré',
        `mode_paiement`            varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `reference_paiement`       varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `date_paiement`            date DEFAULT NULL,
        `id_depense`               int DEFAULT NULL,
        `id_utilisateur`           int DEFAULT NULL,
        `cree_le`                  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_bulletin_periode_ens` (`id_periode`,`matricule_ens`),
        KEY `matricule_ens` (`matricule_ens`),
        KEY `id_utilisateur` (`id_utilisateur`),
        KEY `id_depense` (`id_depense`),
        CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_bulletin_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_bulletin_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
        CONSTRAINT `fk_bulletin_depense` FOREIGN KEY (`id_depense`) REFERENCES `depense` (`id_depense`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table ligne_bulletin_paie",
    "CREATE TABLE IF NOT EXISTS `ligne_bulletin_paie` (
        `id`              int NOT NULL AUTO_INCREMENT,
        `id_bulletin`     int NOT NULL,
        `type_ligne`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
        `libelle`         varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
        `montant`         decimal(12,2) NOT NULL,
        `ordre_affichage` int NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `id_bulletin` (`id_bulletin`),
        CONSTRAINT `fk_ligne_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run(
    "Table remboursement_avance",
    "CREATE TABLE IF NOT EXISTS `remboursement_avance` (
        `id`                 int NOT NULL AUTO_INCREMENT,
        `id_avance`          int NOT NULL,
        `id_bulletin`        int NOT NULL,
        `montant`            decimal(12,2) NOT NULL,
        `date_remboursement` date NOT NULL,
        PRIMARY KEY (`id`),
        KEY `id_avance` (`id_avance`),
        KEY `id_bulletin` (`id_bulletin`),
        CONSTRAINT `fk_rembours_avance` FOREIGN KEY (`id_avance`) REFERENCES `avance_salaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_rembours_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

run("Colonne enseignant.statut_ens",      "ALTER TABLE `enseignant` ADD COLUMN `statut_ens` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif' AFTER `arrondissement_ens`", $DEJA_LA);
run("Colonne enseignant.date_recrutement","ALTER TABLE `enseignant` ADD COLUMN `date_recrutement` date DEFAULT NULL AFTER `statut_ens`", $DEJA_LA);
run("Colonne enseignant.mode_paiement",   "ALTER TABLE `enseignant` ADD COLUMN `mode_paiement` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `date_recrutement`", $DEJA_LA);
run("Colonne enseignant.compte_bancaire", "ALTER TABLE `enseignant` ADD COLUMN `compte_bancaire` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mode_paiement`", $DEJA_LA);
run("Colonne enseignant.lieu_origine_libre", "ALTER TABLE `enseignant` ADD COLUMN `lieu_origine_libre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `compte_bancaire`", $DEJA_LA);

run("Normalisation enseignant.id_grade ('' -> NULL)", "UPDATE `enseignant` SET `id_grade` = NULL WHERE `id_grade` = ''");
run("Contrainte enseignant.id_grade -> grade_enseignant", "ALTER TABLE `enseignant` ADD CONSTRAINT `fk_enseignant_grade` FOREIGN KEY (`id_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE SET NULL ON UPDATE CASCADE", $DEJA_LA);

$grades = [
    ['VAC',   'Vacataire',             40000,  1],
    ['INST1', 'Instituteur adjoint',   60000,  2],
    ['INST2', 'Instituteur',           80000,  3],
    ['PROF',  'Professeur des écoles', 100000, 4],
    ['DIR',   'Direction',             150000, 5],
];
foreach ($grades as [$code, $lib, $sal, $ordre]) {
    run(
        "Grade par défaut : $lib",
        "INSERT IGNORE INTO `grade_enseignant` (`code_grade`,`libelle_grade`,`salaire_base`,`ordre_affichage`) VALUES ('" . addslashes($code) . "','" . addslashes($lib) . "',$sal,$ordre)"
    );
}

echo "\n=== Vérification ===\n";
foreach (['grade_enseignant', 'indemnite_grade', 'contrat_enseignant', 'conge_enseignant', 'avance_salaire', 'periode_paie', 'bulletin_paie', 'ligne_bulletin_paie', 'remboursement_avance'] as $t) {
    $n = db_val("SELECT COUNT(*) FROM `$t`");
    echo "  $t : $n ligne(s)\n";
}
$cols = array_column(db_all("SHOW COLUMNS FROM enseignant"), 'Field');
foreach (['statut_ens', 'date_recrutement', 'mode_paiement', 'compte_bancaire', 'lieu_origine_libre'] as $c) {
    echo "  enseignant.$c " . (in_array($c, $cols, true) ? "présente" : "MANQUANTE !") . "\n";
}

echo "\nMigration v34 terminée.\n";
