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
--  Convention : SQL pur, autosuffisant, idempotent (motif PREPARE : MySQL 9.1
--  n'accepte pas IF NOT EXISTS sur ADD COLUMN).
-- =====================================================================

--  25/10/2026 : rendue idempotente. Rejouée sur une base qui a déjà la colonne
--  (école créée depuis le schéma de référence, version enregistrée restée à 0 :
--  « Duplicate column statut » sur siges), elle ne fait plus rien — et surtout ne
--  repasse pas une école publique en « privé ».
SET @a := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'etablissement' AND COLUMN_NAME = 'statut');
SET @s := IF(@a = 0, 'ALTER TABLE `etablissement` ADD COLUMN `statut` enum(''public'',''prive'') NOT NULL DEFAULT ''public'' AFTER `chef_etablissement_en`', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @s := IF(@a = 0, 'UPDATE `etablissement` SET `statut` = ''prive'' WHERE `id` = 1', 'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
