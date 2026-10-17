-- =====================================================================
--  SIGES — Migration v62 : statut public / privé de l'école primaire
-- =====================================================================
--  Demande du 17/10/2026 : « l'école publique n'a pas de fondateur, donc pas
--  de compte, que ce soit primaire ou secondaire ; quand l'école est privée,
--  il faut le compte fondateur ». Le secondaire avait déjà etablissement.statut
--  (migration secondaire v4) ; le primaire le reçoit ici. Les écoles primaires
--  existantes sont toutes privées : valeur par défaut 'prive', leurs comptes
--  fondateur sont donc conservés. À la création d'une école, le statut choisi
--  décide si le compte fondateur est créé (comptes_defaut_definitions()).
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

SET @col_v62 := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'etablissement' AND column_name = 'statut');
SET @sql_v62 := IF(@col_v62 = 0,
    'ALTER TABLE `etablissement` ADD COLUMN `statut` enum(''public'',''prive'') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''prive'' AFTER `signature`',
    'SELECT 1');
PREPARE st_v62 FROM @sql_v62;
EXECUTE st_v62;
DEALLOCATE PREPARE st_v62;
