-- =====================================================================
--  SIGES — Migration SECONDAIRE v2 : paiement_frais.id_utilisateur nullable
-- =====================================================================
--  `id_utilisateur` (NOT NULL + clé étrangère vers `utilisateur`) empêchait
--  silencieusement l'enregistrement d'un paiement PUBLIC par un membre de
--  l'association en visite écriture (aucun compte `utilisateur` local —
--  $_SESSION['user_id'] absent, cf. association/entrer_ecole.php) : la
--  contrainte de clé étrangère refusait la ligne (0 ne correspond à aucun
--  id_utilisateur réel), bug réel constaté le 18/09/2026 lors du contrôle
--  du warning "Undefined array key user_id". Même principe déjà appliqué
--  au primaire (paiement_frais.id_utilisateur, depense.id_utilisateur —
--  voir ecole_contexte.php) : ces colonnes restent NULL pour une écriture
--  faite « au nom » d'un membre association, la traçabilité réelle étant
--  assurée par journal_audit (base assoc), pas par cette colonne locale.
--  `paiement_prive.id_utilisateur` est déjà nullable — rien à faire là.
--
--  Convention : SQL pur, autosuffisant, idempotent.
-- =====================================================================

ALTER TABLE `paiement_frais` MODIFY `id_utilisateur` int unsigned NULL;
