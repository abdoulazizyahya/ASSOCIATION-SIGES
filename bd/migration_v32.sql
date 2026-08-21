-- =====================================================================
--  jaynitaare_v2 — Migration v32 : couleur "colonne annuelle" (bulletin arabe)
-- =====================================================================
--  Sur le modèle de référence (bd/../modele/ara_ann.pdf), toute la colonne
--  ANNUELLE du bloc RESULTATS DE L'ELEVE (bulletin annuel arabe) est mise
--  en évidence par un fond orange/pêche — pas juste la cellule Moyenne
--  (ce qui était fait avant, avec la couleur grise 'cellule_resultat').
--  Nouvelle couleur dédiée, réglable comme les autres via Paramètres >
--  Couleurs bulletin.
-- =====================================================================

INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
('colonne_annuelle', 'Colonne ANNUELLE (bulletin annuel arabe)', 250, 214, 165);
