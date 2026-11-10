-- =====================================================================
--  SIGES — Migration SECONDAIRE v36
--  Séquences numérotées de façon continue sur l'année (10/11/2026)
-- =====================================================================
--  Avant : « Séquence 1, Séquence 2 » dans CHAQUE trimestre (ordre 1-2).
--  Après : Séquence 1 et 2 au 1er trimestre, 3 et 4 au 2e, 5 et 6 au 3e
--  (sequence.ordre = rang dans l'année : trimestre puis ordre puis id).
--  Le libellé n'est renuméroté que s'il est de la forme « <mot> <nombre> »
--  (Séquence 2, Évaluation 4…) : le mot est conservé, un libellé
--  personnalisé est laissé tel quel. Rejouable sans effet (déjà continu).
--  Sans fonction de fenêtrage (compatible MySQL 5.7 / MariaDB).
-- =====================================================================

DROP TEMPORARY TABLE IF EXISTS `tmp_seq_rang`;

CREATE TEMPORARY TABLE `tmp_seq_rang` AS
SELECT s2.id,
       (SELECT COUNT(*)
          FROM `sequence` s3
          JOIN `trimestre` t3 ON t3.id = s3.id_trim
         WHERE t3.id_annee = t2.id_annee
           AND (t3.ordre < t2.ordre
                OR (t3.ordre = t2.ordre AND (s3.ordre < s2.ordre
                                             OR (s3.ordre = s2.ordre AND s3.id <= s2.id))))) AS rang
  FROM `sequence` s2
  JOIN `trimestre` t2 ON t2.id = s2.id_trim;

UPDATE `sequence` s
  JOIN `tmp_seq_rang` r ON r.id = s.id
   SET s.libelle = CASE
         WHEN s.libelle LIKE '% %'
          AND SUBSTRING_INDEX(TRIM(s.libelle), ' ', -1) REGEXP '^[0-9]+$'
          AND (LENGTH(TRIM(s.libelle)) - LENGTH(REPLACE(TRIM(s.libelle), ' ', ''))) = 1
         THEN CONCAT(SUBSTRING_INDEX(TRIM(s.libelle), ' ', 1), ' ', r.rang)
         ELSE s.libelle
       END,
       s.ordre = r.rang;

DROP TEMPORARY TABLE IF EXISTS `tmp_seq_rang`;
