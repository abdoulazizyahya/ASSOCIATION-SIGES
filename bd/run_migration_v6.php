<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v6 (mysqli procédural, idempotent)
//  À ouvrir dans le navigateur :
//      http://localhost/jaynitaare_v2/bd/run_migration_v6.php
//
//  Crée `decision_conseil` (décision du conseil de classe par élève,
//  Admis/Redouble/A statuer + observation). Table neuve, aucune donnée
//  existante modifiée.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v6 — jaynitaare_v2 (mysqli) ===\n\n";

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

run("Table `decision_conseil`", "
    CREATE TABLE IF NOT EXISTS `decision_conseil` (
      `id_eleve`     INT UNSIGNED NOT NULL,
      `classe`       INT NOT NULL,
      `id_trim`      INT NOT NULL,
      `val_annee`    VARCHAR(10) NOT NULL,
      `decision`     ENUM('Admis','Redouble','A statuer') NOT NULL DEFAULT 'A statuer',
      `observation`  VARCHAR(500) NULL,
      `modifie_le`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_eleve`, `classe`, `id_trim`, `val_annee`),
      KEY `classe` (`classe`),
      KEY `id_trim` (`id_trim`),
      KEY `val_annee` (`val_annee`),
      CONSTRAINT `fk_decision_conseil_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_decision_conseil_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", [1050]);

echo "\n=== Vérification ===\n";
$exists = db_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='decision_conseil'");
echo $exists ? "  Table decision_conseil presente.\n" : "  ECHEC : table absente !\n";

echo "\n✅ Migration v6 terminée.\n";
