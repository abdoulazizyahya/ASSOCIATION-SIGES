-- =====================================================================
--  SIGES — Migration SECONDAIRE v17 : module « Emploi du temps »
-- =====================================================================
--  Grille horaire, salles/ateliers, services (charge horaire : classe ×
--  matière × enseignant × heures/semaine), séances placées dans la grille,
--  contraintes de construction, heures dues des enseignants et paramètres
--  par année scolaire. Pages : secondaire/commun/pages/emploi_temps/.
--  Demande du 15/10/2026 (gestion complète des emplois du temps, détection
--  des enseignants programmés dans deux classes à la même heure).
-- =====================================================================

-- Paramètres par année (jours ouvrés, publication, mentions du PDF…)
CREATE TABLE IF NOT EXISTS `edt_parametre` (
  `id_annee` int unsigned NOT NULL,
  `cle` varchar(60) NOT NULL,
  `valeur` mediumtext,
  PRIMARY KEY (`id_annee`, `cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grille horaire : une ligne par période de la journée (cours ou pause)
CREATE TABLE IF NOT EXISTS `edt_creneau` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `ordre` tinyint unsigned NOT NULL,
  `libelle` varchar(40) NOT NULL,
  `heure_debut` time NOT NULL,
  `heure_fin` time NOT NULL,
  `type` enum('cours','pause') NOT NULL DEFAULT 'cours',
  PRIMARY KEY (`id`),
  KEY `idx_edt_creneau_annee` (`id_annee`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Salles, ateliers, laboratoires (indépendants de l'année)
CREATE TABLE IF NOT EXISTS `edt_salle` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(80) NOT NULL,
  `type` enum('salle','atelier','laboratoire','salle_info','terrain','autre') NOT NULL DEFAULT 'salle',
  `capacite` smallint unsigned DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `commentaire` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_edt_salle_nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Heures dues / grade / discipline affichés sur l'emploi du temps individuel
CREATE TABLE IF NOT EXISTS `edt_enseignant` (
  `id_annee` int unsigned NOT NULL,
  `matricule_ens` int NOT NULL,
  `heures_dues` decimal(4,1) DEFAULT NULL,
  `discipline` varchar(80) DEFAULT NULL,
  `grade` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id_annee`, `matricule_ens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Services : qui enseigne quoi, à quelle classe, combien d'heures par semaine
CREATE TABLE IF NOT EXISTS `edt_service` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_mat` int unsigned NOT NULL,
  `matricule_ens` int DEFAULT NULL,
  `heures` decimal(4,1) NOT NULL DEFAULT 0,
  `duree_min` tinyint unsigned NOT NULL DEFAULT 1,
  `duree_max` tinyint unsigned NOT NULL DEFAULT 2,
  `id_salle` int unsigned DEFAULT NULL,
  `code_commun` varchar(40) DEFAULT NULL,
  `commentaire` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_edt_service_classe` (`id_annee`, `id_classe`),
  KEY `idx_edt_service_ens` (`id_annee`, `matricule_ens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Séances : une ligne par période occupée (une séance de 2 h = 2 lignes)
CREATE TABLE IF NOT EXISTS `edt_seance` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `sous_groupe` varchar(20) NOT NULL DEFAULT '',
  `jour` tinyint unsigned NOT NULL,
  `id_creneau` int unsigned NOT NULL,
  `id_service` int unsigned DEFAULT NULL,
  `id_mat` int unsigned NOT NULL,
  `matricule_ens` int DEFAULT NULL,
  `id_salle` int unsigned DEFAULT NULL,
  `code_commun` varchar(40) DEFAULT NULL,
  `verrou` tinyint(1) NOT NULL DEFAULT 0,
  `origine` enum('manuel','import','auto') NOT NULL DEFAULT 'manuel',
  `maj_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_edt_seance_classe` (`id_annee`, `id_classe`, `sous_groupe`, `jour`, `id_creneau`),
  KEY `idx_edt_seance_ens` (`id_annee`, `matricule_ens`, `jour`, `id_creneau`),
  KEY `idx_edt_seance_salle` (`id_annee`, `id_salle`, `jour`, `id_creneau`),
  KEY `idx_edt_seance_service` (`id_service`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contraintes de construction (règles paramétrables)
CREATE TABLE IF NOT EXISTS `edt_contrainte` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `type` varchar(40) NOT NULL,
  `cible_type` enum('tous','matiere','groupe','enseignant','classe','niveau','salle') NOT NULL DEFAULT 'tous',
  `cible_id` varchar(80) DEFAULT NULL,
  `params` text,
  `force` enum('obligatoire','souhaitee') NOT NULL DEFAULT 'obligatoire',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `commentaire` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_edt_contrainte_annee` (`id_annee`, `actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
