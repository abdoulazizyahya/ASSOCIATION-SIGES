-- =====================================================================
--  SIGES — Migration SECONDAIRE v5 : module Paie / Ressources humaines
-- =====================================================================
--  Porte les tables Paie (grade_enseignant, indemnite_grade,
--  contrat_enseignant, conge_enseignant, avance_salaire, periode_paie,
--  bulletin_paie, ligne_bulletin_paie, remboursement_avance) vers les
--  écoles secondaires créées AVANT ce module (ajouté au schéma de référence
--  le 21/09/2026, jamais migré vers l'existant jusqu'ici) — sans quoi le
--  widget Paie du tableau de bord (secondaire/dashboard_contenu.php, copie
--  conforme du tableau de bord primaire, 22/09/2026) plante sur ces écoles.
--
--  Même définition EXACTE que bd/assoc/schema_ref_ecole_secondaire.sql —
--  voir ce fichier pour le détail (grade_enseignant/indemnite_grade en
--  utf8mb4_unicode_ci pour rester compatible avec enseignant.id_grade,
--  hérité — les autres tables en utf8mb4_0900_ai_ci comme le reste de ce
--  schéma). CREATE TABLE IF NOT EXISTS (pas de DROP) : cette migration ne
--  doit jamais effacer des données de paie déjà saisies si elle tournait
--  une 2e fois par erreur.
--
--  Convention : SQL pur, autosuffisant.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `grade_enseignant` (
  `code_grade` varchar(20) NOT NULL,
  `libelle_grade` varchar(150) NOT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`code_grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `indemnite_grade` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_grade` varchar(20) NOT NULL,
  `libelle_indemnite` varchar(150) NOT NULL,
  `montant` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `code_grade` (`code_grade`),
  CONSTRAINT `fk_indemnite_grade` FOREIGN KEY (`code_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contrat_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_contrat` varchar(30) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `remarques` varchar(255) DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  CONSTRAINT `fk_contrat_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `conge_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_conge` varchar(30) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `nb_jours` int NOT NULL DEFAULT '0',
  `motif` varchar(255) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'Validé',
  `deduit_paie` tinyint(1) NOT NULL DEFAULT '0',
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_conge_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_conge_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `avance_salaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_avance` date NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_avance_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_avance_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `periode_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mois` tinyint NOT NULL,
  `annee` smallint NOT NULL,
  `libelle` varchar(50) NOT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'Brouillon',
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periode_mois_annee` (`mois`,`annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_periode` int NOT NULL,
  `matricule_ens` int NOT NULL,
  `code_grade` varchar(20) DEFAULT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_indemnites` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_primes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_retenues` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_avance_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_absence_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `jours_absence` int NOT NULL DEFAULT '0',
  `brut` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_a_payer` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` varchar(20) NOT NULL DEFAULT 'Généré',
  `mode_paiement` varchar(30) DEFAULT NULL,
  `reference_paiement` varchar(50) DEFAULT NULL,
  `date_paiement` date DEFAULT NULL,
  `id_depense` int unsigned DEFAULT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bulletin_periode_ens` (`id_periode`,`matricule_ens`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `id_depense` (`id_depense`),
  CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_depense` FOREIGN KEY (`id_depense`) REFERENCES `depense_privee` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `ligne_bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_bulletin` int NOT NULL,
  `type_ligne` varchar(10) NOT NULL,
  `code_rubrique` varchar(10) DEFAULT NULL,
  `libelle` varchar(150) NOT NULL,
  `nb` decimal(10,2) DEFAULT NULL,
  `montant` decimal(12,2) NOT NULL,
  `base` decimal(12,2) DEFAULT NULL,
  `taux_pct` decimal(6,2) DEFAULT NULL,
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_ligne_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `remboursement_avance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_avance` int NOT NULL,
  `id_bulletin` int NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_remboursement` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_avance` (`id_avance`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_rembours_avance` FOREIGN KEY (`id_avance`) REFERENCES `avance_salaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rembours_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- FK enseignant.id_grade -> grade_enseignant.code_grade — posée seulement
-- si elle n'existe pas encore (une école déjà migrée via une exécution
-- partielle antérieure ne doit pas replanter dessus).
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_enseignant_grade'
);
SET @sql_fk = IF(@fk_exists = 0,
  'ALTER TABLE `enseignant` ADD CONSTRAINT `fk_enseignant_grade` FOREIGN KEY (`id_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE SET NULL ON UPDATE CASCADE',
  'SELECT 1');
PREPARE stmt_fk FROM @sql_fk;
EXECUTE stmt_fk;
DEALLOCATE PREPARE stmt_fk;

-- Données de référence (mêmes valeurs par défaut que
-- bd/assoc/seed_ref_ecole_secondaire.sql pour une école neuve).
INSERT IGNORE INTO `categorie_depense_privee` (`libelle`, `description`) VALUES
('Salaires', 'Salaires et primes du personnel');

INSERT IGNORE INTO `grade_enseignant` (`code_grade`, `libelle_grade`, `salaire_base`, `ordre_affichage`) VALUES
('VAC',   'Vacataire',              40000,  1),
('CES1',  'Professeur des CES 2e grade', 80000,  2),
('CES2',  'Professeur des CES 1er grade', 100000, 3),
('CEG',   'Professeur des CEG',     70000,  4),
('DIR',   'Direction',              150000, 5);
