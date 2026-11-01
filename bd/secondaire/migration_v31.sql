-- =====================================================================
--  SIGES — Migration SECONDAIRE v31
--  Programme officiel du 2nd cycle (arrêté n°92/22/MINESEC du 17/03/2022)
--  + libellés anglais des matières et des groupes — 01/11/2026
-- =====================================================================
--  1. groupe.libelle_en : « 1er GROUPE / 1st GROUP » sur les bulletins.
--  2. Matières du 2nd cycle manquantes : LITTÉRATURE, PHYSIQUE, CHIMIE
--     (l'arrêté sépare physique et chimie ; « PCT » reste pour le 1er cycle).
--  3. Matières de l'arrêté désactivées jusque-là (langues et cultures
--     nationales, éducation artistique, italien) : réactivées.
--  4. Nom anglais de chaque matière francophone (bulletins « FR / EN »),
--     seulement s'il est vide — jamais un libellé saisi par l'école.
--  5. Série « A » → « A4 » (Langue vivante II et Philosophie) : les classes
--     littéraires ALL/ESP/ITA/CHI/ARA sont toutes de série A4.
--  Coefficients par série : bd/secondaire/reference_programme_2nd_cycle.php
--  (modèle appliqué à la création d'une classe, modifiable par l'école).
--  Écoles du GÉNÉRAL seulement (niveau « 6 eme » présent). Rejouable.
-- =====================================================================

ALTER TABLE `groupe` ADD COLUMN `libelle_en` varchar(250) DEFAULT NULL AFTER `libelle_groupe_comp`;

UPDATE `groupe` g
JOIN (SELECT '1er GROUPE' AS `fr`, '1st GROUP' AS `en` UNION ALL SELECT '2e GROUPE', '2nd GROUP'
      UNION ALL SELECT '3e GROUPE', '3rd GROUP') v ON v.`fr` = g.`libelle_groupe_comp` COLLATE utf8mb4_unicode_ci
SET g.`libelle_en` = v.`en`
WHERE g.`id_section` = 'Fr' AND (g.`libelle_en` IS NULL OR g.`libelle_en` = '');

INSERT INTO `matiere` (`libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`)
SELECT v.`lib`, v.`en`, 'Fr', v.`o`, 1
FROM (SELECT 'LITTÉRATURE' AS `lib`, 'Literature' AS `en`, 23 AS `o`
      UNION ALL SELECT 'PHYSIQUE', 'Physics', 24
      UNION ALL SELECT 'CHIMIE', 'Chemistry', 25) v
WHERE EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme')
  AND EXISTS (SELECT 1 FROM `section_classe` WHERE `libelle_section` = 'Fr')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `libelle` FROM `matiere`) m WHERE m.`libelle` = v.`lib` COLLATE utf8mb4_unicode_ci);

UPDATE `matiere` SET `actif` = 1
WHERE `libelle` IN ('LANGUES NATIONALES', 'CULTURES NATIONALES', 'ÉDUCATION ARTISTIQUE ET CULTURELLE', 'LVII - ITALIEN')
  AND EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme');

UPDATE `matiere` m
JOIN (SELECT 'ANGLAIS' AS `fr`, 'English Language' AS `en`
      UNION ALL SELECT 'INFORMATIQUE', 'Computer Science'
      UNION ALL SELECT 'FRANÇAIS', 'French'
      UNION ALL SELECT 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE', 'Citizenship Education'
      UNION ALL SELECT 'GÉOGRAPHIE', 'Geography'
      UNION ALL SELECT 'HISTOIRE', 'History'
      UNION ALL SELECT 'MATHÉMATIQUES', 'Mathematics'
      UNION ALL SELECT 'SCIENCES', 'Sciences'
      UNION ALL SELECT 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)', 'Physical Education'
      UNION ALL SELECT 'TRAVAIL MANUEL (TM)', 'Manual Labour'
      UNION ALL SELECT 'LVII - ALLEMAND', 'German'
      UNION ALL SELECT 'LVII - ARABE', 'Arabic'
      UNION ALL SELECT 'LVII - ESPAGNOL', 'Spanish'
      UNION ALL SELECT 'LVII - CHINOIS', 'Chinese'
      UNION ALL SELECT 'LVII - ITALIEN', 'Italian'
      UNION ALL SELECT 'PCT', 'Physics, Chemistry and Technology'
      UNION ALL SELECT 'SVTEEHB', 'Biology'
      UNION ALL SELECT 'CULTURES NATIONALES', 'National Cultures'
      UNION ALL SELECT 'ÉDUCATION ARTISTIQUE ET CULTURELLE', 'Arts Education'
      UNION ALL SELECT 'LANGUES NATIONALES', 'National Languages'
      UNION ALL SELECT 'LETTRES CLASSIQUES (LATIN)', 'Latin'
      UNION ALL SELECT 'LETTRES CLASSIQUES (GREC)', 'Greek'
      UNION ALL SELECT 'ÉCONOMIE SOCIALE ET FAMILIALE (ESF)', 'Home Economics'
      UNION ALL SELECT 'PHILOSOPHIE', 'Philosophy') v ON v.`fr` = m.`libelle` COLLATE utf8mb4_unicode_ci
SET m.`libelle_en` = v.`en`
WHERE m.`libelle_section` = 'Fr'
  AND (m.`libelle_en` IS NULL OR m.`libelle_en` = '' OR m.`libelle_en` = 'PHILOSOPHY')
  AND EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme');

UPDATE `serie` SET `libelle` = 'A4'
WHERE `libelle` = 'A'
  AND EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `libelle` FROM `serie`) s WHERE s.`libelle` = 'A4');

-- 6. Code court des matières au nom long (s'il est vide) : sur le bulletin
--    bilingue, « ECM / Citizenship Education » au lieu d'un nom tronqué
--    (sec_sql_matiere_bilingue(), noyau/fonctions.php). Modifiable par l'école.
UPDATE `matiere` m
JOIN (SELECT 'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE' AS `fr`, 'ECM' AS `code`
      UNION ALL SELECT 'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)', 'EPS'
      UNION ALL SELECT 'ÉDUCATION ARTISTIQUE ET CULTURELLE', 'ÉDUC. ARTISTIQUE'
      UNION ALL SELECT 'ÉCONOMIE SOCIALE ET FAMILIALE (ESF)', 'ESF') v ON v.`fr` = m.`libelle` COLLATE utf8mb4_unicode_ci
SET m.`code` = v.`code`
WHERE m.`libelle_section` = 'Fr' AND (m.`code` IS NULL OR m.`code` = '')
  AND EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme');
