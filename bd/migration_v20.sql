-- =====================================================================
--  ABZ_MBE — Migration v20
--  Signature numérique du chef d'établissement (image sélectionnée par
--  l'admin dans les paramètres), appliquée aux documents PDF uniquement
--  quand l'utilisateur le demande explicitement à l'impression (jamais
--  automatique) — voir pdf/header_pdf.php::pdf_signature().
--  À exécuter via bd/run_migration_v20.php.
-- =====================================================================

ALTER TABLE `etablissement` ADD COLUMN `signature` VARCHAR(255) NULL DEFAULT NULL AFTER `logo`;
