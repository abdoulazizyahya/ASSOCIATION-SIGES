-- =====================================================================
--  jaynitaare_v2 — Migration v3
--  Suite de migration_v1.sql / migration_v2.sql.
--
--  Objet (demande utilisateur du 10/08/2026) : gestion des niveaux depuis
--  le sous-menu Classes (Scolarité) — création, modification, suppression,
--  activation/désactivation. `niveau` n'avait jusqu'ici aucun indicateur
--  d'activation ; un niveau désactivé n'est plus proposé pour de nouvelles
--  classes ni dans les écrans de pédagogie (onglets « Groupes par niveau »
--  et « Barème par niveau »), sans toucher aux classes déjà rattachées.
--
--  À exécuter via bd/run_migration_v3.php.
-- =====================================================================

ALTER TABLE `niveau`
  ADD COLUMN `actif` TINYINT(1) NOT NULL DEFAULT 1 AFTER `OrdreNiveau`;
