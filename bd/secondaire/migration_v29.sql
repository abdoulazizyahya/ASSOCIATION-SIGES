-- =====================================================================
--  SIGES — Migration SECONDAIRE v29
--  Groupes de matières 1er / 2e / 3e groupe (30/10/2026)
-- =====================================================================
--  Bulletin « Matières » (secondaire général) : les matières d'une classe
--  sont présentées par groupe, avec pour chaque groupe le total des points,
--  des coefficients et la moyenne du groupe, puis un TOTAL GÉNÉRAL. Ajoute
--  les groupes 1er/2e/3e (section francophone) et 1st/2nd/3rd GROUP
--  (anglophone) ; chaque matière d'une classe est rangée dans son groupe par
--  l'école (Pédagogie → Matières → Affectation par classe → Groupe). Les
--  matières existantes restent dans « Programme francophone/anglophone ».
--  Écoles du GÉNÉRAL seulement (niveau « 6 eme » présent). Rejouable.
-- =====================================================================

INSERT INTO `groupe` (`libelle_groupe_comp`, `id_section`)
SELECT v.`lib`, v.`sec` FROM (
    SELECT '1er GROUPE' AS `lib`, 'Fr' AS `sec`, 1 AS `o` UNION ALL SELECT '2e GROUPE', 'Fr', 2 UNION ALL SELECT '3e GROUPE', 'Fr', 3
    UNION ALL SELECT '1st GROUP', 'An', 4 UNION ALL SELECT '2nd GROUP', 'An', 5 UNION ALL SELECT '3rd GROUP', 'An', 6
) v
WHERE EXISTS (SELECT 1 FROM `niveau` WHERE `code_niveau` = '6 eme')
  AND EXISTS (SELECT 1 FROM `section_classe` s WHERE s.`libelle_section` = v.`sec`)
  AND NOT EXISTS (SELECT 1 FROM (SELECT `libelle_groupe_comp`, `id_section` FROM `groupe`) g
                  WHERE g.`libelle_groupe_comp` = v.`lib` AND g.`id_section` = v.`sec`)
ORDER BY v.`o`;
