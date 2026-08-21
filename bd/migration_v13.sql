-- =====================================================================
--  ABZ_MBE — Migration v13
--  Seuils du Conseil de Classe persistés par classe et par année
--  (inspiré de la table `critere_admis` de MANWI, adaptée : une seule
--  table couvre à la fois les seuils du mode annuel et du mode
--  trimestriel du module pages/conseil_classe/index.php, au lieu de
--  ressaisir les seuils à chaque ouverture).
--  À exécuter via bd/run_migration_v13.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `critere_conseil` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_classe`             INT UNSIGNED NOT NULL,
  `id_annee`              INT UNSIGNED NOT NULL,
  `moyenne_admission`     DECIMAL(4,2) NULL DEFAULT NULL,
  `moyenne_exclusion`     DECIMAL(4,2) NULL DEFAULT NULL,
  `heures_absence_max`    SMALLINT UNSIGNED NULL DEFAULT NULL,
  `jours_exclusion_max`   SMALLINT UNSIGNED NULL DEFAULT NULL,
  `seuil_tableau_honneur` DECIMAL(4,2) NULL DEFAULT NULL,
  `seuil_encouragement`   DECIMAL(4,2) NULL DEFAULT NULL,
  `seuil_felicitation`    DECIMAL(4,2) NULL DEFAULT NULL,
  `maj_le`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_critere_classe_annee` (`id_classe`, `id_annee`),
  CONSTRAINT `fk_critere_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_critere_annee`  FOREIGN KEY (`id_annee`)  REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
