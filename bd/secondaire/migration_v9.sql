-- =====================================================================
--  SIGES — Migration SECONDAIRE v9 : photos des élèves EN BASE
-- =====================================================================
--  Comme au primaire (eleve.Photo_elv), la photo est désormais stockée
--  dans la base de l'école (colonne photo_bin) et servie uniquement à un
--  utilisateur connecté (secondaire/pages/eleves/photo.php). Avant : un
--  fichier dans assets/uploads/eleves/, accessible sans connexion et
--  absent des sauvegardes de l'école. Demande du 03/10/2026.
--
--  eleve.photo est conservée : 'bd' = photo en base ; un ancien nom de
--  fichier = photo pas encore convertie (convertie automatiquement à son
--  premier affichage, ou en lot par bd/assoc/photos_secondaire_vers_bd.php).
--
--  Convention : SQL pur, idempotent (test préalable dans information_schema).
-- =====================================================================

SET @col_existe := (SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = DATABASE() AND table_name = 'eleve' AND column_name = 'photo_bin');
SET @sql_v9 := IF(@col_existe = 0,
    'ALTER TABLE `eleve` ADD COLUMN `photo_bin` MEDIUMBLOB NULL AFTER `photo`',
    'SELECT 1');
PREPARE st_v9 FROM @sql_v9;
EXECUTE st_v9;
DEALLOCATE PREPARE st_v9;
