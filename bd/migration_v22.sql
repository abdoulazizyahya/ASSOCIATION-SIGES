-- =====================================================================
--  ABZ_MBE — Migration v22
--  Étend le catalogue de signataires (Censeur, Surveillant Général) et
--  simplifie signature_titulaire.role_gestion à un rôle unique (ADMIN
--  reste toujours autorisé en secours, vérifié en PHP — voir
--  fonctions.php::signature_role_autorisee()).
--  Ajoute enseignant.signature : chaque enseignant (potentiellement
--  Professeur Principal) peut avoir sa propre signature, appliquée
--  dynamiquement sur les documents de SA classe (pas de position
--  configurable individuellement — voir pdf/header_pdf.php).
--  À exécuter via bd/run_migration_v22.php.
-- =====================================================================

ALTER TABLE `enseignant` ADD COLUMN `signature` VARCHAR(255) NULL DEFAULT NULL AFTER `matiere_enseignee`;

UPDATE `signature_titulaire` SET `role_gestion` = 'PROVISEUR' WHERE `code` = 'chef_etablissement';

INSERT IGNORE INTO `signature_titulaire` (`code`, `libelle`, `role_gestion`) VALUES
 ('censeur', 'Censeur', 'CENSEUR'),
 ('surveillant_general', 'Surveillant Général', 'SG');
