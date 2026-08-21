<?php
// =====================================================================
//  jaynitaare_v2 — Runner de la migration v7 (mysqli procédural, idempotent)
//  http://localhost/jaynitaare_v2/bd/run_migration_v7.php
//
//  Piste arabe : UNIQUE sur moyenne_sequence_arabe (aucun doublon existant,
//  vérifié) + nouvelle table moyenne_annuelle_arabe.
// =====================================================================
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../connexion.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=== Migration v7 — jaynitaare_v2 (mysqli) ===\n\n";

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

// 1061 = Duplicate key name (déjà exécutée précédemment)
run("UNIQUE moyenne_sequence_arabe",
    "ALTER TABLE `moyenne_sequence_arabe` ADD UNIQUE KEY `uk_moy_seq_ar_eleve_seq_classe_annee` (`id_eleve`, `id_seq`, `classe`, `val_annee`)",
    [1061]);

run("Table `moyenne_annuelle_arabe`", "
    CREATE TABLE IF NOT EXISTS `moyenne_annuelle_arabe` (
      `id_eleve`  INT UNSIGNED NOT NULL,
      `classe`    INT NOT NULL,
      `moy`       FLOAT NOT NULL,
      `Nb_trim`   INT NOT NULL,
      `val_annee` VARCHAR(10) NOT NULL,
      UNIQUE KEY `uk_moy_an_ar_eleve_classe_annee` (`id_eleve`, `classe`, `val_annee`),
      KEY `classe` (`classe`),
      KEY `val_annee` (`val_annee`),
      CONSTRAINT `fk_moyenne_annuelle_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_moyenne_annuelle_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_moyenne_annuelle_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", [1050]);

echo "\n=== Vérification ===\n";
$dupes = db_val("SELECT COUNT(*) FROM (SELECT id_eleve, id_seq, classe, val_annee FROM moyenne_sequence_arabe GROUP BY id_eleve, id_seq, classe, val_annee HAVING COUNT(*)>1) t");
echo "  Doublons restants moyenne_sequence_arabe : $dupes\n";
$exists = db_val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='moyenne_annuelle_arabe'");
echo $exists ? "  Table moyenne_annuelle_arabe presente.\n" : "  ECHEC : table absente !\n";

echo "\n✅ Migration v7 terminée.\n";
