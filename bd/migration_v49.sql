-- =====================================================================
--  jaynitaare_v2 — Migration v49 : composer_sequence_arabe, suppression
--  de la colonne `note`. Prérequis (faits avant d'exécuter cette
--  migration) : un barème discipline_arabe actif pour TOUTE matière
--  utilisée (note_matiere_sequence_arabe()/points_matiere_sequence_arabe()
--  ne lisent plus que note_orale/note_ecrite/note_pratique — voir
--  notes_apc_arabe.php et pages/notes_arabe/index.php).
-- =====================================================================

ALTER TABLE `composer_sequence_arabe`
    DROP COLUMN `note`;
