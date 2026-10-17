-- =====================================================================
--  SIGES — Migration SECONDAIRE v19 : matricule officiel du personnel
-- =====================================================================
--  Demande du 17/10/2026 : le « matricule » affiché jusqu'ici était
--  l'identifiant interne (enseignant.matricule_ens, entier auto-incrémenté,
--  clé de toutes les jointures). Le vrai matricule est alphanumérique
--  (ex. JP23444, 402683, 561345-M) : nouvelle colonne `matricule`, saisie sur
--  la fiche, facultative, unique quand elle est renseignée. L'identifiant
--  interne n'est plus affiché.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

SET @col_v19 := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'matricule');
SET @sql_v19 := IF(@col_v19 = 0,
    'ALTER TABLE `enseignant` ADD COLUMN `matricule` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `matricule_ens`',
    'SELECT 1');
PREPARE st_v19 FROM @sql_v19;
EXECUTE st_v19;
DEALLOCATE PREPARE st_v19;

SET @idx_v19 := (SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND index_name = 'uq_enseignant_matricule');
SET @sql_v19 := IF(@idx_v19 = 0,
    'ALTER TABLE `enseignant` ADD UNIQUE KEY `uq_enseignant_matricule` (`matricule`)',
    'SELECT 1');
PREPARE st_v19 FROM @sql_v19;
EXECUTE st_v19;
DEALLOCATE PREPARE st_v19;
