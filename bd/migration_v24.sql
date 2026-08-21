-- =====================================================================
--  ABZ_MBE — Migration v24
--  Couleurs du fond dégradé des reçus de paiement (pages/paiements/
--  recu.php, recap_paiement.php, recu_apee.php, recu_fiche.php) réglables
--  par l'utilisateur au lieu d'être fixées en dur dans pdf_fond_degrade()
--  (pdf/header_pdf.php) — mêmes 3 couleurs pâles par défaut que
--  l'implémentation initiale (jaune → rose → vert).
--  À exécuter via bd/run_migration_v24.php.
-- =====================================================================

ALTER TABLE `reglage_paiement`
  ADD COLUMN `couleur_fond_1` VARCHAR(7) NOT NULL DEFAULT '#FFF6C8' AFTER `montant_frais_operateur`,
  ADD COLUMN `couleur_fond_2` VARCHAR(7) NOT NULL DEFAULT '#FFCDD2' AFTER `couleur_fond_1`,
  ADD COLUMN `couleur_fond_3` VARCHAR(7) NOT NULL DEFAULT '#CDE8CD' AFTER `couleur_fond_2`;
