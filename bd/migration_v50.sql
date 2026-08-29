-- =====================================================================
--  jaynitaare_v2 — Migration v50 : suppression de classe_matiere_arabe
-- =====================================================================
--  Piste arabe : les matières ne s'affectent plus classe par classe —
--  uniquement par NIVEAU (matiere_niveau_arabe), automatiquement reprises
--  par toutes les classes du niveau (matieres_classe_arabe(), notes_apc_arabe.php).
--  Backfill d'abord (certaines classes n'avaient leurs matières QUE via
--  l'ancien onglet « Matières par classe », jamais reprises au niveau),
--  puis suppression de la table.
-- =====================================================================

INSERT IGNORE INTO matiere_niveau_arabe (code_niveau, id_mat, ordre, actif)
SELECT DISTINCT c.Niveau, cma.id_mat, cma.ordre, 1
FROM classe_matiere_arabe cma
JOIN classe c ON c.IDClasses = cma.code_classe;

DROP TABLE IF EXISTS `classe_matiere_arabe`;
