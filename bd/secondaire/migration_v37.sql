-- =====================================================================
--  SIGES — Migration SECONDAIRE v37
--  Ordre d'affichage des groupes, des matières et des compétences (12/11/2026)
-- =====================================================================
--  « Ordre » = rang d'affichage partout (listes ET bulletins) : 1 en haut,
--  puis 2, 3… Cette migration fige l'ordre ACTUEL des bulletins :
--   • groupe.ordre (nouvelle colonne) = rang par identifiant (tri actuel) ;
--   • discipline.ordre = 1..n par classe, dans l'ordre actuel du bulletin
--     (groupe, ordre, libellé) — écoles du général seulement : le
--     technique garde ses valeurs et son tri (consigne « ne pas toucher ») ;
--   • competence.ordre = 1..n par matière / niveau / trimestre.
--  Sans fonction de fenêtrage (MySQL 5.7 / MariaDB). Rejouable.
-- =====================================================================

ALTER TABLE `groupe` ADD COLUMN `ordre` int NOT NULL DEFAULT '1' AFTER `id_section`;

-- ── Groupes ──────────────────────────────────────────────────────────
DROP TEMPORARY TABLE IF EXISTS `tmp_grp_b`;
CREATE TEMPORARY TABLE `tmp_grp_b` AS SELECT `id_groupe_comp` AS id FROM `groupe`;
UPDATE `groupe` g
   SET g.`ordre` = (SELECT COUNT(*) FROM `tmp_grp_b` b WHERE b.id <= g.`id_groupe_comp`);
DROP TEMPORARY TABLE IF EXISTS `tmp_grp_b`;

-- ── Matières d'une classe (général seulement) ───────────────────────
DROP TEMPORARY TABLE IF EXISTS `tmp_disc_a`;
DROP TEMPORARY TABLE IF EXISTS `tmp_disc_b`;
CREATE TEMPORARY TABLE `tmp_disc_a` AS
SELECT d.`id_mat`, d.`IDClasses`, d.`id_groupe`, CAST(d.`ordre` AS SIGNED) AS o,
       CAST(COALESCE(m.`libelle`, '') AS CHAR(120)) AS lib, CAST(0 AS SIGNED) AS rang
  FROM `discipline` d LEFT JOIN `matiere` m ON m.`id` = d.`id_mat`;
CREATE TEMPORARY TABLE `tmp_disc_b` AS SELECT * FROM `tmp_disc_a`;
UPDATE `tmp_disc_a` a
   SET a.`rang` = (SELECT COUNT(*) FROM `tmp_disc_b` b
                    WHERE b.`IDClasses` = a.`IDClasses`
                      AND (b.`id_groupe` < a.`id_groupe`
                           OR (b.`id_groupe` = a.`id_groupe`
                               AND (b.o < a.o
                                    OR (b.o = a.o AND (b.lib < a.lib
                                                       OR (b.lib = a.lib AND b.`id_mat` <= a.`id_mat`)))))));
UPDATE `discipline` d
  JOIN `tmp_disc_a` a ON a.`id_mat` = d.`id_mat` AND a.`IDClasses` = d.`IDClasses` AND a.`id_groupe` = d.`id_groupe`
   SET d.`ordre` = CAST(a.`rang` AS CHAR)
 WHERE COALESCE((SELECT e.`ordre_enseignement` FROM `etablissement` e LIMIT 1), 'general') <> 'technique';
DROP TEMPORARY TABLE IF EXISTS `tmp_disc_a`;
DROP TEMPORARY TABLE IF EXISTS `tmp_disc_b`;

-- ── Compétences (par matière / niveau / trimestre) ──────────────────
DROP TEMPORARY TABLE IF EXISTS `tmp_comp_a`;
DROP TEMPORARY TABLE IF EXISTS `tmp_comp_b`;
CREATE TEMPORARY TABLE `tmp_comp_a` AS
SELECT `id`, `id_matiere`, `code_niveau`, `id_trim`, `ordre`, CAST(0 AS SIGNED) AS rang FROM `competence`;
CREATE TEMPORARY TABLE `tmp_comp_b` AS SELECT * FROM `tmp_comp_a`;
UPDATE `tmp_comp_a` a
   SET a.`rang` = (SELECT COUNT(*) FROM `tmp_comp_b` b
                    WHERE b.`id_matiere` = a.`id_matiere` AND b.`code_niveau` = a.`code_niveau` AND b.`id_trim` = a.`id_trim`
                      AND (b.`ordre` < a.`ordre` OR (b.`ordre` = a.`ordre` AND b.`id` <= a.`id`)));
UPDATE `competence` c JOIN `tmp_comp_a` a ON a.`id` = c.`id` SET c.`ordre` = a.`rang`;
DROP TEMPORARY TABLE IF EXISTS `tmp_comp_a`;
DROP TEMPORARY TABLE IF EXISTS `tmp_comp_b`;
