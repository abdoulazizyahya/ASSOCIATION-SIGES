-- =====================================================================
--  jaynitaare_v2 — Migration v43
--  Mode de paiement d'un versement (paiement_frais.mode_paiement) : Espèces
--  (valeur par défaut — les 518+ versements historiques sont tous en
--  espèces), Orange Money, MTN Mobile Money, Virement bancaire, Autre.
--  Demande utilisateur du 20/08/2026 : « pouvoir connaître le mode de
--  paiement partout (saisie, affichages, statistiques) ». Voir
--  fonctions.php::finances_modes_paiement() pour les libellés/icônes.
-- =====================================================================

ALTER TABLE `paiement_frais`
  ADD COLUMN `mode_paiement` ENUM('ESPECES','ORANGE_MONEY','MOMO','BANQUE','AUTRE')
    NOT NULL DEFAULT 'ESPECES' AFTER `ref_paiement`;
