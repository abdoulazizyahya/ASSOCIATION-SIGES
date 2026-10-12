-- =====================================================================
--  SIGES — Migration SECONDAIRE v13 : ordre d'enseignement général / technique
-- =====================================================================
--  Une école secondaire est soit d'enseignement GÉNÉRAL (programme APC,
--  notes par compétence — schéma porté de LAM_ABZ), soit d'enseignement
--  TECHNIQUE (notes par séquence, matières groupées Enseignement
--  professionnel / général / autres, sections Commerciale / Industrielle —
--  porté d'ABZ_MBE). Choisi à la création de l'école, figé ensuite.
--  Les écoles secondaires existantes sont toutes d'enseignement général.
--  Demande du 12/10/2026.
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `ordre_enseignement` enum('general','technique') NOT NULL DEFAULT 'general' AFTER `statut`;
