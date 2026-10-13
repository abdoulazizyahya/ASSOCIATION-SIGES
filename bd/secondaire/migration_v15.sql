-- =====================================================================
--  SIGES — Migration SECONDAIRE v15 : délivrance des documents enseignants
-- =====================================================================
--  Attestation de présence effective, certificats de prise / reprise de
--  service — deux modes, choisis par le propriétaire ou un superadmin de
--  l'association (Paramètres > Documents enseignants) :
--    validation : circuit existant — demande, vérification du Censeur,
--                 validation du Proviseur, notifications à chaque étape ;
--    autonome   : l'enseignant fait sa demande puis imprime lui-même ses
--                 documents, qu'il soumet ensuite au Proviseur pour
--                 signature manuscrite.
--  demande_document.mode_traitement garde le mode sous lequel chaque
--  demande a été faite : changer de mode n'affecte jamais les demandes déjà
--  en cours dans le circuit. Demande du 13/10/2026.
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `mode_documents_enseignant` enum('validation','autonome') NOT NULL DEFAULT 'validation' AFTER `ordre_enseignement`;

ALTER TABLE `demande_document`
  ADD COLUMN `mode_traitement` enum('validation','autonome') NOT NULL DEFAULT 'validation' AFTER `statut`;
