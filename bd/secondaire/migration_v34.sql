-- =====================================================================
--  SIGES — Migration SECONDAIRE v34
--  Plusieurs matières enseignées par membre du personnel (07/11/2026)
-- =====================================================================
--  La fiche du personnel propose désormais des cases à cocher pour les
--  matières enseignées (séparées par « , ») : 120 caractères ne suffisent
--  plus. Les autres champs de carrière (qualité, diplôme, spécialité,
--  poste antérieur) passent en listes de choix sans changement de schéma.
-- =====================================================================

ALTER TABLE `enseignant`
  MODIFY COLUMN `matiere_enseignee` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
