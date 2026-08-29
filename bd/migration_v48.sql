-- =====================================================================
--  jaynitaare_v2 — Migration v48 : composer_sequence_arabe, suppression
--  des colonnes etat_composition/justification (vides, 5754/5754 lignes,
--  jamais lues par le code). `note` N'EST PAS supprimée : elle reste la
--  seule source de note tant qu'aucun barème discipline_arabe n'est actif
--  (note_matiere_sequence_arabe() s'y rabat systématiquement dans ce cas).
-- =====================================================================

ALTER TABLE `composer_sequence_arabe`
    DROP COLUMN `etat_composition`,
    DROP COLUMN `justification`;
