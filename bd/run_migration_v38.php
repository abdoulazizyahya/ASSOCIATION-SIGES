<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v38 (mysqli procédural, idempotent)
//  php bd/run_migration_v38.php   (ou via navigateur)
//  Notes justifiées (absences lors d'une évaluation) — piste FR + AR.
//  Voir bd/migration_v38.sql pour le détail.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v38 — jaynitaare_v2 (mysqli) ===\n\n";

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

$DEJA_LA = [1060, 1061, 1826, 1005, 1050];

run(
    "Table absence_justifiee",
    "CREATE TABLE `absence_justifiee` (
        `id_eleve`       int unsigned NOT NULL,
        `id_comp`        int NOT NULL,
        `id_seq`         int NOT NULL,
        `IDClasses`      int NOT NULL,
        `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
        `justifie`       tinyint(1) NOT NULL DEFAULT 0,
        `raison`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `modifie_le`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_eleve`,`id_comp`,`id_seq`),
        KEY `idx_absjust_classe_seq` (`IDClasses`,`id_seq`),
        KEY `id_utilisateur` (`id_utilisateur`),
        CONSTRAINT `fk_absjust_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjust_comp` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjust_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjust_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjust_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

run(
    "Table absence_justifiee_arabe",
    "CREATE TABLE `absence_justifiee_arabe` (
        `id_eleve`       int unsigned NOT NULL,
        `id_mat`         int NOT NULL,
        `id_seq`         int NOT NULL,
        `classe`         int NOT NULL,
        `justifie`       tinyint(1) NOT NULL DEFAULT 0,
        `raison`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `id_utilisateur` int DEFAULT NULL,
        `modifie_le`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_eleve`,`id_mat`,`id_seq`),
        KEY `idx_absjustar_classe_seq` (`classe`,`id_seq`),
        KEY `id_utilisateur` (`id_utilisateur`),
        CONSTRAINT `fk_absjustar_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjustar_mat` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjustar_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
        CONSTRAINT `fk_absjustar_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

echo "\n=== Vérification ===\n";
foreach (['absence_justifiee', 'absence_justifiee_arabe'] as $t) {
    $existe = db_val("SHOW TABLES LIKE '$t'");
    echo "  $t : " . ($existe ? 'présente' : 'MANQUANTE — ÉCHEC') . "\n";
}

echo "\nMigration v38 terminée.\n";
