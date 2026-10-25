-- =====================================================================
--  SIGES — Migration SECONDAIRE v22 : affectations par période + départ
--  d'un enseignant (24/10/2026)
-- =====================================================================
--  1. dispenser_periode : historique des affectations enseignant ↔ matière
--     ↔ classe, à partir d'un trimestre (ordre 1, 2, 3). Le bulletin d'un
--     trimestre affiche l'enseignant de CE trimestre, même s'il a été
--     remplacé depuis (dispenser reste l'affectation EN COURS, lue partout
--     ailleurs). matricule_ens = 0 : plus d'enseignant à partir de ce
--     trimestre. Les affectations existantes sont reprises à partir du T1.
--  2. enseignant.statut_ens / date_depart / motif_depart : un enseignant
--     muté, parti ou retraité n'est plus supprimé — il sort des listes de
--     choix mais garde sa fiche et son nom sur les anciens bulletins.
--     (statut_ens : même nom qu'au primaire ; l'import d'emploi du temps
--     l'écrivait déjà.)
--  Idempotente : rejouable sans erreur.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `dispenser_periode` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL DEFAULT 0,
  `IDClasses` int NOT NULL,
  `id_mat` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `trim_debut` tinyint unsigned NOT NULL DEFAULT 1,
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_disp_periode` (`IDClasses`,`id_mat`,`val_annee`,`trim_debut`),
  KEY `idx_disp_periode_ens` (`matricule_ens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `dispenser_periode` (`matricule_ens`, `IDClasses`, `id_mat`, `val_annee`, `trim_debut`)
SELECT `matricule_ens`, `IDClasses`, `id_mat`, `val_annee`, 1 FROM `dispenser`;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'statut_ens');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `statut_ens` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''actif''', 'SELECT 1');
PREPARE st_v22 FROM @s; EXECUTE st_v22; DEALLOCATE PREPARE st_v22;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'date_depart');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `date_depart` date DEFAULT NULL', 'SELECT 1');
PREPARE st_v22 FROM @s; EXECUTE st_v22; DEALLOCATE PREPARE st_v22;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'motif_depart');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `motif_depart` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v22 FROM @s; EXECUTE st_v22; DEALLOCATE PREPARE st_v22;
