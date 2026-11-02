-- =====================================================================
--  SIGES — Migration SECONDAIRE v33
--  Langue d'enseignement de l'établissement (01/11/2026)
-- =====================================================================
--  francophone, anglophone ou bilingue (deux sections Fr / An). Choisie à
--  la création de l'école, modifiable dans Paramètres → Établissement.
--  École bilingue : le bulletin d'une classe de la section anglophone est
--  en anglais avec la traduction française (langue_enseignement_courante(),
--  sec_langue_classe()). Les écoles qui ont déjà des élèves dans des
--  classes « An » sont marquées bilingues.
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `langue_enseignement` enum('francophone','anglophone','bilingue') NOT NULL DEFAULT 'francophone' AFTER `ordre_enseignement`;

UPDATE `etablissement` SET `langue_enseignement` = 'bilingue'
 WHERE EXISTS (SELECT 1 FROM `classe` c JOIN `inscription` i ON i.`id_classe` = c.`id` WHERE c.`libelle_section` = 'An');
