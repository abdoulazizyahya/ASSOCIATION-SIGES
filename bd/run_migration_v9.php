<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v9 (mysqli procédural, idempotent)
//  http://localhost/jaynitaare_v2/bd/run_migration_v9.php
//  Conseil de classe — piste arabe (miroir des tables migration_v6/v8).
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v9 — jaynitaare_v2 (mysqli) ===\n\n";

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

run("Table `decision_conseil_arabe`", "
    CREATE TABLE IF NOT EXISTS `decision_conseil_arabe` (
      `id_eleve`    INT UNSIGNED NOT NULL,
      `classe`      INT NOT NULL,
      `id_trim`     INT NOT NULL,
      `val_annee`   VARCHAR(10) NOT NULL,
      `decision`    VARCHAR(30) NOT NULL DEFAULT 'RAS',
      `observation` VARCHAR(500) NULL,
      `modifie_le`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_eleve`, `classe`, `id_trim`, `val_annee`),
      KEY `classe` (`classe`), KEY `id_trim` (`id_trim`), KEY `val_annee` (`val_annee`),
      CONSTRAINT `fk_decision_conseil_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_arabe_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", [1050]);

run("Table `decision_conseil_annuel_arabe`", "
    CREATE TABLE IF NOT EXISTS `decision_conseil_annuel_arabe` (
      `id_eleve`     INT UNSIGNED NOT NULL,
      `classe`       INT NOT NULL,
      `val_annee`    VARCHAR(10) NOT NULL,
      `decision`     VARCHAR(30) NOT NULL DEFAULT '',
      `next_classe`  INT NULL,
      `observation`  VARCHAR(500) NULL,
      `modifie_le`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_eleve`, `classe`, `val_annee`),
      KEY `classe` (`classe`), KEY `val_annee` (`val_annee`),
      CONSTRAINT `fk_decision_annuel_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_annuel_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_annuel_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_annuel_arabe_next_classe` FOREIGN KEY (`next_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", [1050]);

run("Table `critere_conseil_arabe`", "
    CREATE TABLE IF NOT EXISTS `critere_conseil_arabe` (
      `id_classe`               INT NOT NULL,
      `val_annee`               VARCHAR(10) NOT NULL,
      `moyenne_admission`       DECIMAL(4,2) NULL,
      `moyenne_exclusion`       DECIMAL(4,2) NULL,
      `heures_absence_max`      INT NULL,
      `jours_exclusion_max`     INT NULL,
      `seuil_tableau_honneur`   DECIMAL(4,2) NULL,
      `seuil_encouragement`     DECIMAL(4,2) NULL,
      `seuil_felicitation`      DECIMAL(4,2) NULL,
      PRIMARY KEY (`id_classe`, `val_annee`),
      CONSTRAINT `fk_critere_conseil_arabe_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_critere_conseil_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", [1050]);

echo "\n=== Vérification ===\n";
foreach (['decision_conseil_arabe', 'decision_conseil_annuel_arabe', 'critere_conseil_arabe'] as $t) {
    $exists = db_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$t]);
    echo $exists ? "  Table $t presente.\n" : "  ECHEC : table $t absente !\n";
}
echo "\n✅ Migration v9 terminée.\n";
