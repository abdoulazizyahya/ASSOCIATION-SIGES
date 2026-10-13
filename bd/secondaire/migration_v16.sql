-- =====================================================================
--  SIGES — Migration SECONDAIRE v16 : compte propriétaire (compte système)
-- =====================================================================
--  utilisateur.compte_systeme = 1 : compte du propriétaire / superadmin de
--  l'association, créé par défaut dans chaque école (COMPTE_PROPRIETAIRE_*,
--  config/config.php). Invisible et non modifiable pour les autres comptes
--  de l'école (Utilisateurs). Demande du 13/10/2026.
-- =====================================================================

ALTER TABLE `utilisateur`
  ADD COLUMN `compte_systeme` tinyint(1) NOT NULL DEFAULT 0 AFTER `actif`;
