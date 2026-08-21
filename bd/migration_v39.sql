-- =====================================================================
--  jaynitaare_v2 — Migration v39
--  "Cas social" : remplace le select "Indigent ou né de parents indigent"
--  (info_supplementaires.indigent, conservée mais retirée de l'écran) par
--  une case à cocher + un pourcentage de réduction appliqué au montant dû
--  sur les paiements de frais (pages/finances/*). Voir prompt du 17/08/2026.
-- =====================================================================

ALTER TABLE `info_supplementaires`
  ADD COLUMN `cas_social` TINYINT(1) NOT NULL DEFAULT 0 AFTER `indigent`,
  ADD COLUMN `pourcentage_reduction` DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `cas_social`;
