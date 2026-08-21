-- =====================================================================
--  jaynitaare_v2 — Migration v2
--  Suite de migration_v1.sql (voir son en-tête pour le rappel sur les
--  fichiers migration_v11.sql à migration_v25.sql, restes inertes d'ABZ_MBE).
--
--  Objet (demande utilisateur du 09/08/2026) :
--   1) `groupe_competence` doit avoir un ordre d'affichage explicite :
--      partout où les groupes sont listés (cet écran, et plus tard
--      saisie/bulletins/stats), l'ordre doit suivre ce champ plutôt que
--      l'ordre d'insertion (id_groupe_comp). Les 12 lignes actuelles (6
--      domaines Fr + leurs 6 doublons An) sont réamorcées en miroir : le
--      domaine An n reçoit le même ordre que son équivalent Fr n, pour que
--      les deux versions restent alignées sur un bulletin bilingue.
--   2) `discipline` (barème, déjà par classe/compétence/année) reçoit un
--      indicateur `actif` : une compétence désactivée pour un niveau donné
--      (répliqué sur ses classes, comme le reste du barème) ne doit plus
--      apparaître nulle part (saisie, bulletin, stats — modules futurs).
--      Si toutes les compétences actives d'un groupe deviennent inactives
--      pour un niveau, ce groupe devient automatiquement invisible pour ce
--      niveau — calculé à la lecture (voir groupe_competence_est_visible()
--      dans fonctions.php), sans écrire dans groupe_competence_niveau.actif
--      (qui reste la bascule *manuelle* de l'onglet « Groupes par niveau »).
--
--  À exécuter via bd/run_migration_v2.php.
-- =====================================================================

ALTER TABLE `groupe_competence`
  ADD COLUMN `ordre_affichage` INT NOT NULL DEFAULT 0 AFTER `langue`;

UPDATE `groupe_competence` SET `ordre_affichage` = CASE `id_groupe_comp`
  WHEN 1  THEN 1  WHEN 2  THEN 2  WHEN 3  THEN 3
  WHEN 4  THEN 4  WHEN 5  THEN 5  WHEN 6  THEN 6
  WHEN 7  THEN 1  WHEN 8  THEN 2  WHEN 9  THEN 3
  WHEN 10 THEN 4  WHEN 11 THEN 5  WHEN 12 THEN 6
  ELSE `id_groupe_comp`
END;

ALTER TABLE `discipline`
  ADD COLUMN `actif` TINYINT(1) NOT NULL DEFAULT 1 AFTER `total_points`;
