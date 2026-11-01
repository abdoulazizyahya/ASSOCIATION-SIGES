-- =====================================================================
--  SIGES — Migration SECONDAIRE v27
--  Comptes par défaut sans « Général » (30/10/2026)
-- =====================================================================
--  Les comptes créés avec l'école s'appelaient « Proviseur Général »,
--  « Censeur Général », « Secrétaire Général »… : prénom retiré
--  (comptes_defaut_definitions()). Seuls les comptes par défaut (nom = la
--  fonction) sont touchés. Rejouable.
-- =====================================================================

UPDATE `utilisateur` SET `prenom` = NULL
WHERE `prenom` = 'Général'
  AND `nom` IN ('Proviseur', 'Principal', 'Censeur', 'Secrétaire', 'Intendant', 'Fondateur');
