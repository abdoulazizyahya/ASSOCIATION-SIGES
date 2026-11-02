-- =====================================================================
--  SIGES — Migration PRIMAIRE v70
--  Langue d'enseignement de l'établissement (01/11/2026)
-- =====================================================================
--  francophone, anglophone ou bilingue (niveaux de section Fr et An) —
--  même réglage que le secondaire (migration secondaire v33). Choisie à la
--  création de l'école, modifiable dans Paramètres → Établissement. Décide
--  de la langue proposée par défaut à l'impression des bulletins (bulletins
--  anglais = bulletin_{trimestriel,annuel}_anglais.php). Les écoles qui ont
--  déjà des élèves dans des classes de niveau « An » sont marquées bilingues.
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `langue_enseignement` enum('francophone','anglophone','bilingue') NOT NULL DEFAULT 'francophone' AFTER `statut`;

UPDATE `etablissement` SET `langue_enseignement` = 'bilingue'
 WHERE EXISTS (SELECT 1 FROM `classe` c JOIN `niveau` n ON n.`LibelleNiveau` = c.`Niveau` JOIN `inscrire` i ON i.`IDClasses` = c.`IDClasses` WHERE n.`Section` = 'An');
