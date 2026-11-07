-- =====================================================================
--  SIGES — Migration SECONDAIRE v35
--  Ordre du catalogue des matières = ordre actuel au bulletin (08/11/2026)
-- =====================================================================
--  matiere.ordre ne servait qu'à trier le catalogue. Il devient l'ordre
--  proposé par défaut quand on affecte une matière à une classe
--  (Matières → Affectation). Il est initialisé d'après ce que les bulletins
--  affichent aujourd'hui : l'ordre (discipline.ordre) le plus fréquent de la
--  matière parmi les classes où elle est affectée — le plus petit en cas
--  d'égalité. Les classes jamais ordonnées (toutes leurs matières au même
--  ordre, souvent 1 : bulletin alphabétique) ne comptent que si la matière
--  n'est affectée à aucune classe ordonnée. Matière jamais affectée : ordre
--  inchangé. Rejouable sans risque (recalcul depuis les classes).
--  Sans fonction de fenêtrage (compatible MySQL 5.7 / MariaDB).
-- =====================================================================

-- 1) Toutes les classes.
UPDATE `matiere` m
JOIN (
    SELECT c.id_mat, MIN(c.o) AS ordre_bulletin
    FROM (
        SELECT id_mat, CAST(ordre AS UNSIGNED) AS o, COUNT(*) AS n
        FROM `discipline`
        WHERE ordre REGEXP '^[0-9]+$' AND CAST(ordre AS UNSIGNED) BETWEEN 1 AND 30
        GROUP BY id_mat, CAST(ordre AS UNSIGNED)
    ) c
    JOIN (
        SELECT id_mat, MAX(n) AS n_max
        FROM (
            SELECT id_mat, COUNT(*) AS n
            FROM `discipline`
            WHERE ordre REGEXP '^[0-9]+$' AND CAST(ordre AS UNSIGNED) BETWEEN 1 AND 30
            GROUP BY id_mat, CAST(ordre AS UNSIGNED)
        ) t
        GROUP BY id_mat
    ) x ON x.id_mat = c.id_mat AND x.n_max = c.n
    GROUP BY c.id_mat
) b ON b.id_mat = m.id
SET m.`ordre` = b.ordre_bulletin;

-- 2) Classes réellement ordonnées seulement (prend le pas sur 1).
UPDATE `matiere` m
JOIN (
    SELECT c.id_mat, MIN(c.o) AS ordre_bulletin
    FROM (
        SELECT id_mat, CAST(ordre AS UNSIGNED) AS o, COUNT(*) AS n
        FROM `discipline`
        WHERE ordre REGEXP '^[0-9]+$' AND CAST(ordre AS UNSIGNED) BETWEEN 1 AND 30
          AND IDClasses IN (SELECT k.IDClasses FROM (SELECT IDClasses FROM `discipline` GROUP BY IDClasses HAVING COUNT(DISTINCT ordre) > 1) k)
        GROUP BY id_mat, CAST(ordre AS UNSIGNED)
    ) c
    JOIN (
        SELECT id_mat, MAX(n) AS n_max
        FROM (
            SELECT id_mat, COUNT(*) AS n
            FROM `discipline`
            WHERE ordre REGEXP '^[0-9]+$' AND CAST(ordre AS UNSIGNED) BETWEEN 1 AND 30
          AND IDClasses IN (SELECT k.IDClasses FROM (SELECT IDClasses FROM `discipline` GROUP BY IDClasses HAVING COUNT(DISTINCT ordre) > 1) k)
            GROUP BY id_mat, CAST(ordre AS UNSIGNED)
        ) t
        GROUP BY id_mat
    ) x ON x.id_mat = c.id_mat AND x.n_max = c.n
    GROUP BY c.id_mat
) b ON b.id_mat = m.id
SET m.`ordre` = b.ordre_bulletin;
