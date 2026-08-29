<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v46 (mysqli procédural, idempotent)
//  php bd/run_migration_v46.php   (ou via navigateur)
//  Piste arabe : « Matières par niveau » + « Barème par niveau »
//  (Oral/Écrit/Pratique). Voir bd/migration_v46.sql.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';
require_once __DIR__ . '/../fonctions.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v46 — jaynitaare_v2 (mysqli) ===\n\n";

function run(string $label, string $sql, array $ignoreCodes = []): void {
    global $link;
    try {
        mysqli_query($link, $sql);
        echo "OK  $label (" . mysqli_affected_rows($link) . " ligne(s))\n";
    } catch (mysqli_sql_exception $e) {
        if (in_array($e->getCode(), $ignoreCodes, true)) {
            echo "--  $label - déjà en place (ignoré)\n";
        } else {
            echo "ERR $label - [" . $e->getCode() . "] " . $e->getMessage() . "\n";
        }
    }
}

// 1060 colonne dupliquée, 1061 index dupliqué, 1826/1005 FK dupliquée,
// 1050 table déjà existante, 1062 entrée dupliquée (même liste que v45).
$DEJA_LA = [1060, 1061, 1826, 1005, 1050, 1062];

run(
    "matiere_arabe.id_groupe (colonne)",
    "ALTER TABLE `matiere_arabe` ADD COLUMN `id_groupe` INT NULL AFTER `matiere_ar`",
    $DEJA_LA
);
run(
    "matiere_arabe.id_groupe (clé étrangère)",
    "ALTER TABLE `matiere_arabe` ADD CONSTRAINT `fk_matarabe_groupe` FOREIGN KEY (`id_groupe`)
        REFERENCES `groupe_matiere_arabe` (`id_groupe`) ON DELETE SET NULL ON UPDATE CASCADE",
    $DEJA_LA
);

// Backfill : matières sans groupe seulement, depuis classe_matiere_arabe.
db_exec(
    "UPDATE matiere_arabe m
     SET m.id_groupe = (SELECT MIN(cma.id_groupe) FROM classe_matiere_arabe cma WHERE cma.id_mat = m.id_mat)
     WHERE m.id_groupe IS NULL"
);
echo "OK  Backfill matiere_arabe.id_groupe depuis classe_matiere_arabe\n";

run(
    "Table matiere_niveau_arabe",
    "CREATE TABLE IF NOT EXISTS `matiere_niveau_arabe` (
      `code_niveau` VARCHAR(10) NOT NULL,
      `id_mat`      INT NOT NULL,
      `ordre`       INT NOT NULL DEFAULT 1,
      `actif`       TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`code_niveau`, `id_mat`),
      CONSTRAINT `fk_matniv_niveau` FOREIGN KEY (`code_niveau`)
          REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_matniv_mat` FOREIGN KEY (`id_mat`)
          REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

run(
    "Table discipline_arabe",
    "CREATE TABLE IF NOT EXISTS `discipline_arabe` (
      `IDClasses`    INT NOT NULL,
      `id_mat`       INT NOT NULL,
      `annee_scol`   VARCHAR(10) NOT NULL,
      `orale`        FLOAT NOT NULL DEFAULT 0,
      `ecrite`       FLOAT NOT NULL DEFAULT 0,
      `pratique`     FLOAT NOT NULL DEFAULT 0,
      `total_points` FLOAT NOT NULL DEFAULT 0,
      `actif`        TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (`IDClasses`, `id_mat`, `annee_scol`),
      CONSTRAINT `fk_discar_classe` FOREIGN KEY (`IDClasses`)
          REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_discar_mat` FOREIGN KEY (`id_mat`)
          REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    $DEJA_LA
);

run("composer_sequence_arabe.note_orale", "ALTER TABLE `composer_sequence_arabe` ADD COLUMN `note_orale` FLOAT NULL", $DEJA_LA);
run("composer_sequence_arabe.note_ecrite", "ALTER TABLE `composer_sequence_arabe` ADD COLUMN `note_ecrite` FLOAT NULL", $DEJA_LA);
run("composer_sequence_arabe.note_pratique", "ALTER TABLE `composer_sequence_arabe` ADD COLUMN `note_pratique` FLOAT NULL", $DEJA_LA);
run("composer_sequence_arabe.note_total_points", "ALTER TABLE `composer_sequence_arabe` ADD COLUMN `note_total_points` FLOAT NULL", $DEJA_LA);

echo "\n=== Vérification ===\n";
echo "matiere_arabe avec id_groupe défini : " . (int) db_val("SELECT COUNT(*) FROM matiere_arabe WHERE id_groupe IS NOT NULL") . "/" . (int) db_val("SELECT COUNT(*) FROM matiere_arabe") . "\n";
echo "Table matiere_niveau_arabe : " . (db_val("SHOW TABLES LIKE 'matiere_niveau_arabe'") ? "présente" : "MANQUANTE") . "\n";
echo "Table discipline_arabe : " . (db_val("SHOW TABLES LIKE 'discipline_arabe'") ? "présente" : "MANQUANTE") . "\n";
$cols = array_column(db_all("SHOW COLUMNS FROM composer_sequence_arabe"), 'Field');
foreach (['note_orale', 'note_ecrite', 'note_pratique', 'note_total_points'] as $c) {
    echo "composer_sequence_arabe.$c : " . (in_array($c, $cols, true) ? "présente" : "MANQUANTE") . "\n";
}

echo "\nMigration v46 terminée.\n";
