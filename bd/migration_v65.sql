-- =====================================================================
--  SIGES — Migration v65 : titulaires de classe par trimestre + départ d'un
--  membre du personnel (primaire, 24/10/2026)
-- =====================================================================
--  Même principe que la migration SECONDAIRE v22 :
--  1. enseignat_classe_periode : historique des affectations enseignant ↔
--     classe, piste française ('fr' = enseignat_classe) ou arabe ('ar' =
--     enseignat_classe_arabe), à partir d'un trimestre (rang 1, 2, 3 dans
--     l'année). Le bulletin d'un trimestre affiche le titulaire de CE
--     trimestre, même remplacé depuis ; les tables enseignat_classe(_arabe)
--     restent l'affectation EN COURS. matricule_ens = 0 : plus personne à
--     partir de ce trimestre. Les affectations existantes sont reprises dès le T1.
--  2. enseignant.date_depart / motif_depart : un membre muté, parti ou
--     retraité (statut_ens 'mute' / 'parti' / 'retraite') garde sa fiche et
--     son nom sur les anciens bulletins.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `enseignat_classe_periode` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `piste` enum('fr','ar') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fr',
  `matricule_ens` int NOT NULL DEFAULT 0,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `trim_debut` tinyint unsigned NOT NULL DEFAULT 1,
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ens_classe_periode` (`piste`,`IDClasses`,`val_annee`,`trim_debut`,`matricule_ens`),
  KEY `idx_ecp_ens` (`matricule_ens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `enseignat_classe_periode` (`piste`, `matricule_ens`, `IDClasses`, `val_annee`, `trim_debut`)
SELECT 'fr', `matricule_ens`, `IDClasses`, `val_annee`, 1 FROM `enseignat_classe`;
INSERT IGNORE INTO `enseignat_classe_periode` (`piste`, `matricule_ens`, `IDClasses`, `val_annee`, `trim_debut`)
SELECT 'ar', `matricule_ens`, `IDClasses`, `val_annee`, 1 FROM `enseignat_classe_arabe`;

SET @col_v65 := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'date_depart');
SET @sql_v65 := IF(@col_v65 = 0, 'ALTER TABLE `enseignant` ADD COLUMN `date_depart` date DEFAULT NULL', 'SELECT 1');
PREPARE st_v65 FROM @sql_v65; EXECUTE st_v65; DEALLOCATE PREPARE st_v65;

SET @col_v65 := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'motif_depart');
SET @sql_v65 := IF(@col_v65 = 0, 'ALTER TABLE `enseignant` ADD COLUMN `motif_depart` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v65 FROM @sql_v65; EXECUTE st_v65; DEALLOCATE PREPARE st_v65;
