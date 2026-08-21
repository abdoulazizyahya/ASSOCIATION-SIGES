-- =====================================================================
--  jaynitaare_v2 — Migration v44
--  Rétro-remplissage de `groupe_competence_niveau` pour les niveaux déjà
--  configurés via `discipline` (barème réel déjà en place : I, II, III)
--  mais jamais explicitement associés à leurs groupes de compétences via
--  l'onglet « Groupes par niveau » (pages/competences/liste.php).
--
--  Nécessaire pour la nouvelle règle du 21/08/2026 (demande explicite) :
--  l'onglet « Barème par niveau » n'affiche désormais QUE les groupes
--  assignés à un niveau, et invite à les assigner d'abord si aucun ne
--  l'est — sans ce rattrapage, les niveaux déjà en production (barème réel
--  déjà configuré, 13 compétences chacun) se retrouveraient soudainement
--  sans aucun groupe visible.
--
--  Un groupe est considéré "en usage" pour un niveau s'il a au moins une
--  ligne `discipline` pour une classe de ce niveau, sur l'année scolaire
--  ACTIVE (`annee_scolaire.Etat_annee_scolaire=1`) — même source que
--  l'écran « Barème par niveau » lui-même pour afficher les valeurs
--  actuelles. INSERT IGNORE : idempotent, ne touche jamais un niveau déjà
--  configuré manuellement (n'écrase aucune ligne existante).
-- =====================================================================

INSERT IGNORE INTO `groupe_competence_niveau` (`code_niveau`, `id_groupe_comp`, `actif`)
SELECT DISTINCT c.Niveau, g.id_groupe_comp, 1
FROM `discipline` d
JOIN `competence` comp ON comp.id_comp = d.id_comp
JOIN `groupe_competence` g ON g.id_groupe_comp = comp.id_groupe_comp
JOIN `classe` c ON c.IDClasses = d.IDClasses
JOIN `annee_scolaire` a ON a.val_annee = d.annee_scol AND a.Etat_annee_scolaire = 1
WHERE g.langue = 'Fr';
