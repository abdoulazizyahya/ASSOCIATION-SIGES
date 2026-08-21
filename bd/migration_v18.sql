-- =====================================================================
--  ABZ_MBE — Migration v18
--  Numéro d'immatriculation de l'établissement (identifiant officiel,
--  affiché dans les paramètres et disponible pour les documents officiels).
--  À exécuter via bd/run_migration_v18.php.
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `immatriculation` VARCHAR(50) NULL DEFAULT NULL AFTER `sigle`;

UPDATE `etablissement` SET `immatriculation` = '2JH1TEFD110316102' WHERE `id` = 1;
