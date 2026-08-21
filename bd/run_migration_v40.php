<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v40 (mysqli procédural, idempotent)
//  php bd/run_migration_v40.php   (ou via navigateur)
//  Absences en jours (plus en heures) + annulation d'évaluation (remplace
//  l'annulation de trimestre). Voir bd/migration_v40.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v40 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1054 = colonne source introuvable (déjà renommée), 1050 = table déjà là.
run(
    "absence.nbre_heure_non_jus -> nbre_jour_non_jus",
    "ALTER TABLE `absence` CHANGE COLUMN `nbre_heure_non_jus` `nbre_jour_non_jus` int NOT NULL",
    [1054]
);
run(
    "absence.nbre_heure_jus -> nbre_jour_jus",
    "ALTER TABLE `absence` CHANGE COLUMN `nbre_heure_jus` `nbre_jour_jus` int NOT NULL",
    [1054]
);
run(
    "critere_conseil.heures_absence_max -> jours_absence_max",
    "ALTER TABLE `critere_conseil` CHANGE COLUMN `heures_absence_max` `jours_absence_max` int NULL",
    [1054]
);
run(
    "critere_conseil_arabe.heures_absence_max -> jours_absence_max",
    "ALTER TABLE `critere_conseil_arabe` CHANGE COLUMN `heures_absence_max` `jours_absence_max` int NULL",
    [1054]
);

run(
    "Suppression table trimestre_annule (0 ligne réelle, jamais utilisée)",
    "DROP TABLE IF EXISTS `trimestre_annule`"
);

run(
    "Table evaluation_annulee",
    "CREATE TABLE IF NOT EXISTS `evaluation_annulee` (
        `id`             int NOT NULL AUTO_INCREMENT,
        `id_eleve`       int unsigned NOT NULL,
        `id_seq`         int NOT NULL,
        `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
        `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `annule_le`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_eval_annule_eleve_seq_annee` (`id_eleve`, `id_seq`, `val_annee`),
        KEY `id_seq` (`id_seq`),
        CONSTRAINT `fk_eval_annule_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_eval_annule_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_eval_annule_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_eval_annule_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    [1050]
);

echo "\n=== Vérification ===\n";
$cols_abs = array_column(db_all("SHOW COLUMNS FROM absence"), 'Field');
echo (in_array('nbre_jour_non_jus', $cols_abs, true) && in_array('nbre_jour_jus', $cols_abs, true))
    ? "  absence.nbre_jour_non_jus / nbre_jour_jus présentes.\n"
    : "  ECHEC : colonnes jour absentes sur `absence` !\n";

$cols_crit = array_column(db_all("SHOW COLUMNS FROM critere_conseil"), 'Field');
echo in_array('jours_absence_max', $cols_crit, true)
    ? "  critere_conseil.jours_absence_max présente.\n"
    : "  ECHEC : colonne jours_absence_max absente !\n";
$cols_crit_ar = array_column(db_all("SHOW COLUMNS FROM critere_conseil_arabe"), 'Field');
echo in_array('jours_absence_max', $cols_crit_ar, true)
    ? "  critere_conseil_arabe.jours_absence_max présente.\n"
    : "  ECHEC : colonne jours_absence_max absente (arabe) !\n";

$table_old = db_all("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='trimestre_annule'");
echo !$table_old ? "  Table trimestre_annule bien supprimée.\n" : "  ECHEC : trimestre_annule existe encore !\n";

$table_new = db_all("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='evaluation_annulee'");
echo $table_new ? "  Table evaluation_annulee présente.\n" : "  ECHEC : table evaluation_annulee absente !\n";

echo "\nMigration v40 terminée.\n";
