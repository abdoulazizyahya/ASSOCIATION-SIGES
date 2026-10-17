-- =====================================================================
--  SIGES — Migration v63 : module « Emploi du temps » au PRIMAIRE
-- =====================================================================
--  Demande du 17/10/2026 : appliquer au primaire le module Emploi du temps du
--  secondaire (secondaire/commun/pages/emploi_temps/, migration secondaire v17).
--  Mêmes tables edt_* ; le module lit les données propres au primaire par des
--  sous-requêtes qui les présentent comme au secondaire (fonction edt_t()) :
--   • cours = groupes de compétences (piste française) et matières arabes,
--     catalogue edt_matiere avec un libellé court modifiable (id 1000+ = arabe) ;
--   • enseignant pris dans « Affectation des classes » (enseignat_classe pour
--     la piste française, enseignat_classe_arabe pour la piste arabe) ;
--   • année : id = première année du libellé (2026/2027 → 2026).
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
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

-- ── Catalogue des cours de l'emploi du temps (libellé court modifiable) ──
CREATE TABLE IF NOT EXISTS `edt_matiere` (
  `id` int NOT NULL,
  `libelle` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_long` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` enum('fr','ar') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fr',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `edt_matiere` (id, libelle, libelle_long, source)
SELECT g.id_groupe_comp,
       CASE
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%ENGL%'        THEN 'English'
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%MATH%'        THEN 'Maths & science'
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%CITIZEN%'     THEN 'Citizenship'
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%AUTONOMY%'    THEN 'Vocational studies'
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%INFORMATION%' THEN 'ICT'
         WHEN g.langue = 'An' AND UPPER(g.libelle_groupe_comp) LIKE '%PHYSICAL%'    THEN 'PE & arts'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%FRAN%'        THEN 'Français'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%MATH%'        THEN 'Maths & sciences'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%CITOYEN%'     THEN 'Éducation civique'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%AUTONOMIE%'   THEN 'Travaux pratiques'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%INFORMATION%' THEN 'Informatique'
         WHEN UPPER(g.libelle_groupe_comp) LIKE '%PHYSIQUE%'    THEN 'EPS & arts'
         ELSE LEFT(TRIM(g.libelle_groupe_comp), 40)
       END,
       TRIM(g.libelle_groupe_comp), 'fr'
  FROM `groupe_competence` g;

INSERT IGNORE INTO `edt_matiere` (id, libelle, libelle_long, source)
SELECT 1000 + m.id_mat,
       CASE
         WHEN m.matiere_fr LIKE '%Quran%' OR m.matiere_fr LIKE '%Coran%' THEN 'Coran'
         WHEN m.matiere_fr LIKE '%grammatic%'      THEN 'Grammaire arabe'
         WHEN m.matiere_fr LIKE '%oralement%'      THEN 'Expression / lecture'
         WHEN m.matiere_fr LIKE '%Compréhension%'  THEN 'Compréhension écrite'
         WHEN m.matiere_fr LIKE '%Copier%'         THEN 'Écriture'
         WHEN m.matiere_fr LIKE '%Dialoguer%'      THEN 'Dialogue'
         WHEN m.matiere_fr LIKE '%foi%'            THEN 'Tawhid'
         WHEN m.matiere_fr LIKE '%biographie%'     THEN 'Sîra'
         WHEN m.matiere_fr LIKE '%culte%'          THEN 'Fiqh'
         WHEN m.matiere_fr LIKE '%invoquer%'       THEN 'Invocations'
         ELSE LEFT(TRIM(COALESCE(NULLIF(m.matiere_fr, ''), m.matiere_ar)), 40)
       END,
       TRIM(COALESCE(NULLIF(m.matiere_fr, ''), m.matiere_ar)), 'ar'
  FROM `matiere_arabe` m;

-- Pas de vues SQL : sur un serveur qui héberge de nombreuses écoles, les requêtes
-- préparées sur des vues échouent (MySQL 1615). Le module lit les données du primaire
-- par des sous-requêtes (edt_t(), _edt_lib.php). Vues d'un premier essai retirées :
DROP VIEW IF EXISTS `edt_v_annee`, `edt_v_classe`, `edt_v_niveau`, `edt_v_groupe`, `edt_v_discipline`, `edt_v_principal`;
