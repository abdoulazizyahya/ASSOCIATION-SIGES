-- =====================================================================
--  SIGES — Migration SECONDAIRE v4 : statut public/privé de l'établissement
-- =====================================================================
--  Détermine le libellé "Proviseur" (public) vs "Principal" (privé) affiché
--  à l'écran, ET quel module Paiements est visible (public : obligation_frais
--  / paiement_frais ; privé : obligation_privee / paiement_prive — jamais les
--  deux menus à la fois, voir layout/menu_secondaire.php). Demande explicite
--  du 22/09/2026.
--
--  Les 2 écoles secondaires existantes à ce jour sont toutes les deux des
--  établissements PRIVÉS (confirmé) — mises à 'prive' ci-dessous. Nouvelle
--  colonne DEFAULT 'public' pour toute AUTRE base sur laquelle cette
--  migration tournerait (comportement inchangé tant que personne n'y touche).
--
--  Convention : SQL pur, autosuffisant. Pas idempotent au sens strict (ADD
--  COLUMN simple — MySQL 9.1 de ce serveur ne supporte pas IF NOT EXISTS sur
--  ADD COLUMN, testé le 22/09/2026) : ne doit tourner qu'une fois par école,
--  ce que garantit déjà schema_version_etab (assoc_migrer_ecole()).
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `statut` enum('public','prive') NOT NULL DEFAULT 'public' AFTER `chef_etablissement_en`;

UPDATE `etablissement` SET `statut` = 'prive' WHERE `id` = 1;
