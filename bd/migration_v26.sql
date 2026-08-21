-- =====================================================================
--  jaynitaare_v2 — Migration v26
--  Module Finances : traçabilité de l'agent qui encaisse un versement.
--  Sauvegarde prise avant (bd/backup_paiement_frais_avant_migration_v26_*.sql).
--  Pas de colonne mode_paiement : demande explicite de l'utilisateur, tous
--  les paiements de l'établissement se font en espèces, pas d'opérateurs
--  (Mobile Money etc.) à distinguer ici.
--  Pas de colonne numero_recu séparée : le numéro de reçu est dérivé de
--  id_pay via finances_numero_recu() (fonctions.php) — pas de séquence à
--  maintenir, jamais de collision.
--  À exécuter via bd/run_migration_v26.php.
-- =====================================================================

ALTER TABLE `paiement_frais`
  ADD COLUMN `id_utilisateur` INT NULL AFTER `ref_paiement`;

ALTER TABLE `paiement_frais`
  ADD KEY `id_utilisateur` (`id_utilisateur`);

ALTER TABLE `paiement_frais`
  ADD CONSTRAINT `fk_paiement_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE;
