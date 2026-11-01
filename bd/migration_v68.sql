-- =====================================================================
--  SIGES — Migration PRIMAIRE v68
--  Comptes par défaut sans « Général » (30/10/2026)
-- =====================================================================
--  Les comptes créés avec l'école s'appelaient « Directeur Général »,
--  « Fondateur Général » : prénom retiré (comptes_defaut_definitions()).
--  Seules les fiches par défaut (nom = la fonction) sont touchées.
--  Rejouable.
-- =====================================================================

UPDATE `enseignant` SET `prenom_ens` = NULL
WHERE `prenom_ens` = 'Général' AND `nom_ens` IN ('Directeur', 'Fondateur');
