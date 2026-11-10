-- =====================================================================
--  SIGES — Migration PRIMAIRE v71
--  Téléphones des parents (10/11/2026) — préparation des SMS / WhatsApp
-- =====================================================================
--  Jusqu'ici le primaire n'avait aucun champ téléphone pour les parents :
--  les numéros étaient saisis dans « Adresse » de l'élève (Adresse_elv).
--  - eleve.tel_parent : téléphone du parent à contacter (fiche élève, import)
--  - parent.telephone : téléphone du père, de la mère ou du tuteur
--  Les numéros déjà saisis dans Adresse_elv (9 chiffres commençant par 6 ou
--  2, avec ou sans +237, espaces, points ou tirets) sont RECOPIÉS dans
--  tel_parent ; Adresse_elv n'est pas modifiée. Rejouable sans effet.
-- =====================================================================

ALTER TABLE `eleve`
  ADD COLUMN `tel_parent` varchar(30) DEFAULT NULL AFTER `Adresse_elv`;

ALTER TABLE `parent`
  ADD COLUMN `telephone` varchar(30) DEFAULT NULL AFTER `profession`;

UPDATE `eleve`
   SET `tel_parent` = RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(`Adresse_elv`), ' ', ''), '.', ''), '-', ''), '+', ''), 9)
 WHERE (`tel_parent` IS NULL OR `tel_parent` = '')
   AND REPLACE(REPLACE(REPLACE(REPLACE(TRIM(`Adresse_elv`), ' ', ''), '.', ''), '-', ''), '+', '') REGEXP '^(00237|237)?[26][0-9]{8}$';
