-- =====================================================================
--  SIGES — Migration SECONDAIRE v28
--  Modèle de bulletin par cycle (APC / Matières) + 2nd cycle — 30/10/2026
-- =====================================================================
--  1. bulletin_modele_cycle : pour chaque cycle (niveau.id_cycle), modèle
--     de bulletin choisi dans Paramètres :
--       APC      = compétences (saisie par compétence, bulletin APC) ;
--       MATIERES = ancien bulletin par matière, notes saisies par séquence
--                  (pages secondaire/general/pages/notes_matieres/ et
--                  bulletins_matieres/, copiées du technique).
--     APC par défaut. Le technique n'est pas concerné (toujours par séquence).
--  2. 2nd cycle de l'enseignement général : niveaux 2nde, 1ère, Tle, série A
--     (C et D existent déjà), matière PHILOSOPHIE. Matières et coefficients
--     proposés par série : bd/secondaire/reference_programme_2nd_cycle.php.
--  Seulement pour une école du GÉNÉRAL (niveau « 6 eme » présent). Rejouable.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `bulletin_modele_cycle` (
  `id_cycle` varchar(50) NOT NULL,
  `modele` enum('APC','MATIERES') NOT NULL DEFAULT 'APC',
  `maj_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cycle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `niveau` (`code_niveau`, `libelle_niv`, `id_cycle`, `ordre_niveau`)
SELECT v.`code`, v.`lib`, '2nd Cycle', v.`ordre`
FROM (SELECT '2nde' AS `code`, 'Seconde' AS `lib`, '5' AS `ordre`
      UNION ALL SELECT '1ere', 'Première', '6'
      UNION ALL SELECT 'Tle', 'Terminale', '7') v
WHERE EXISTS (SELECT 1 FROM (SELECT `code_niveau` FROM `niveau`) n WHERE n.`code_niveau` = '6 eme');

INSERT INTO `serie` (`libelle`, `id_filiere`)
SELECT 'A', NULL FROM DUAL
WHERE EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `libelle` FROM `serie`) s WHERE s.`libelle` = 'A');

INSERT INTO `matiere` (`libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`)
SELECT 'PHILOSOPHIE', 'PHILOSOPHY', 'Fr', 1, 1 FROM DUAL
WHERE EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme')
  AND EXISTS (SELECT 1 FROM `section_classe` WHERE `libelle_section` = 'Fr')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `libelle` FROM `matiere`) m WHERE m.`libelle` = 'PHILOSOPHIE');

INSERT IGNORE INTO `bulletin_modele_cycle` (`id_cycle`, `modele`)
SELECT DISTINCT n.`id_cycle`, 'APC' FROM `niveau` n
WHERE n.`id_cycle` IS NOT NULL AND n.`id_cycle` <> ''
  AND EXISTS (SELECT 1 FROM (SELECT `code_niveau` FROM `niveau`) x WHERE x.`code_niveau` = '6 eme');
