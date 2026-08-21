-- =====================================================================
--  ABZ_MBE — Migration v14
--  Mode de paiement autorisé par frais : certains frais (APEE, Livret
--  médical...) se paient en espèces, d'autres (Inscription, Examen...)
--  doivent obligatoirement passer par un opérateur (mobile money, banque).
--  À exécuter via bd/run_migration_v14.php.
-- =====================================================================

ALTER TABLE `obligation_frais`
  ADD COLUMN `mode_paiement` ENUM('cash','operateur') NOT NULL DEFAULT 'cash' AFTER `montant`;
