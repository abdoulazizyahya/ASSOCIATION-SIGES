-- =====================================================================
--  jaynitaare_v2 — Migration v47 : couleurs du nouveau tableau de
--  compétences du bulletin trimestriel arabe (modèle bd/../modele/centre.pdf)
-- =====================================================================

INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
('entete_tableau_arabe', 'En-tête du tableau de compétences (bulletin trim. arabe)', 0, 153, 153),
('groupe_tableau_arabe', 'Bandeaux de groupe du tableau de compétences (bulletin trim. arabe)', 252, 213, 180),
('totaux_tableau_arabe', 'Ligne TOTAUX du tableau de compétences (bulletin trim. arabe)', 197, 217, 241);
