-- =====================================================================
--  ABZ_MBE — Migration v12
--  Module Paiement des frais de scolarité (inspiré de MANWI, réécrit
--  proprement — voir prompt_continuite_ABZ_MBE_1.md pour le détail des
--  écarts volontaires par rapport au système de référence).
--  À exécuter via bd/run_migration_v12.php.
-- =====================================================================

ALTER TABLE `utilisateur`
  MODIFY COLUMN `role` ENUM('ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT','INTENDANT')
  NOT NULL DEFAULT 'SECRETAIRE';

CREATE TABLE IF NOT EXISTS `obligation_frais` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle`     VARCHAR(150) NOT NULL,
  `code_niveau` VARCHAR(25) NOT NULL,
  `montant`     DECIMAL(10,2) NOT NULL,
  `id_annee`    INT UNSIGNED NOT NULL,
  `actif`       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_obligation` (`libelle`, `code_niveau`, `id_annee`),
  KEY `idx_obligation_niveau` (`code_niveau`),
  KEY `idx_obligation_annee` (`id_annee`),
  CONSTRAINT `fk_obligation_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON UPDATE CASCADE,
  CONSTRAINT `fk_obligation_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `operateur_paiement` (
  `id`      VARCHAR(20) NOT NULL,
  `libelle` VARCHAR(60) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT IGNORE INTO `operateur_paiement` (`id`, `libelle`) VALUES
  ('CASH',     'Espèces'),
  ('CAMPOST',  'CAMPOST'),
  ('AFRILAND', 'Afriland First Bank'),
  ('ECOBANK',  'Ecobank'),
  ('EU',       'Express Union'),
  ('MOMO',     'MTN Mobile Money'),
  ('OM',       'Orange Money'),
  ('UBA',      'UBA');

CREATE TABLE IF NOT EXISTS `paiement_frais` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_eleve`       INT UNSIGNED NOT NULL,
  `id_classe`      INT UNSIGNED NOT NULL,
  `id_annee`       INT UNSIGNED NOT NULL,
  `id_obligation`  INT UNSIGNED NOT NULL,
  `id_operateur`   VARCHAR(20) NOT NULL,
  `montant`        DECIMAL(10,2) NOT NULL,
  `ref_paiement`   VARCHAR(50) NULL DEFAULT NULL,
  `date_paiement`  DATE NOT NULL,
  `id_utilisateur` INT UNSIGNED NOT NULL,
  `numero_recu`    VARCHAR(20) NOT NULL,
  `cree_le`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_numero_recu` (`numero_recu`),
  KEY `idx_pf_eleve` (`id_eleve`, `id_annee`, `id_obligation`),
  KEY `idx_pf_classe` (`id_classe`, `id_annee`),
  CONSTRAINT `fk_pf_eleve`      FOREIGN KEY (`id_eleve`)       REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_classe`     FOREIGN KEY (`id_classe`)      REFERENCES `classe` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_annee`      FOREIGN KEY (`id_annee`)       REFERENCES `annee_scolaire` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_obligation` FOREIGN KEY (`id_obligation`)  REFERENCES `obligation_frais` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_operateur`  FOREIGN KEY (`id_operateur`)   REFERENCES `operateur_paiement` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `apee_config` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annee`       INT UNSIGNED NOT NULL,
  `nom_president`  VARCHAR(150) NULL DEFAULT NULL,
  `nom_tresorier`  VARCHAR(150) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_apee_annee` (`id_annee`),
  CONSTRAINT `fk_apee_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
