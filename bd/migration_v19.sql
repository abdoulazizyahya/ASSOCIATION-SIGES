-- =====================================================================
--  ABZ_MBE — Migration v19
--  Seuils des mentions automatiques du bulletin (Tableau d'honneur,
--  Encouragement, Félicitation, Avertissement/Blâme travail,
--  Avertissement/Blâme conduite) — configurables par l'administrateur
--  au lieu d'être codés en dur dans pages/bulletins/pdf.php. Un seul jeu
--  de seuils par année scolaire (politique d'établissement uniforme,
--  distinct de `critere_conseil` qui est propre à chaque classe pour les
--  décisions du Conseil de Classe).
--  À exécuter via bd/run_migration_v19.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `reglage_mention_bulletin` (
  `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annee`                  INT UNSIGNED NOT NULL,
  `moy_tableau_honneur`       DECIMAL(4,2) NOT NULL DEFAULT 12.00,
  `heures_max_tableau_honneur` SMALLINT UNSIGNED NOT NULL DEFAULT 8,
  `moy_encouragement`         DECIMAL(4,2) NOT NULL DEFAULT 14.00,
  `moy_felicitation`          DECIMAL(4,2) NOT NULL DEFAULT 15.00,
  `moy_avert_travail_min`     DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  `moy_avert_travail_max`     DECIMAL(4,2) NOT NULL DEFAULT 7.30,
  `moy_blame_travail_max`     DECIMAL(4,2) NOT NULL DEFAULT 5.00,
  `heures_avert_conduite_min` SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `heures_avert_conduite_max` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `heures_blame_conduite_min` SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reglage_annee` (`id_annee`),
  CONSTRAINT `fk_reglage_mention_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
