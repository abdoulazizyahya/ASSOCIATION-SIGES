-- =====================================================================
--  SIGES — Migration SECONDAIRE v30
--  Lieu d'édition des documents (01/11/2026)
-- =====================================================================
--  La ville de l'école n'est pas toujours le lieu où les documents sont
--  établis (ex. lycée bilingue de Lam : ville Figuil, documents faits à
--  LAM). Vide = la ville de l'école, comme avant (etab_pour_pdf()['lieu']).
-- =====================================================================

ALTER TABLE `etablissement` ADD COLUMN `lieu_edition` varchar(100) DEFAULT NULL AFTER `ville`;
