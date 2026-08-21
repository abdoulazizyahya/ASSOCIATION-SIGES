-- =====================================================================
--  ABZ_MBE — Migration v17
--  Logo par opérateur de paiement (affichage visuel dans le sélecteur de
--  paiement, l'historique et le reçu PDF, au lieu d'une liste texte).
--  À exécuter via bd/run_migration_v17.php.
-- =====================================================================

ALTER TABLE `operateur_paiement`
  ADD COLUMN `logo` VARCHAR(255) NULL DEFAULT NULL AFTER `libelle`;
