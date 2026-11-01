-- =====================================================================
--  SIGES — Migration SECONDAIRE v32
--  Format du matricule interne des élèves (01/11/2026)
-- =====================================================================
--  Le matricule était toujours « sigle + année + numéro » (LYBILAM260001),
--  trop long pour certaines écoles. Préfixe et suffixe réglables dans
--  Élèves → Import & matricules (ex. LB260001L) ; vides = sigle, sans
--  suffixe, comme avant (gen_matricule_secondaire()).
-- =====================================================================

ALTER TABLE `etablissement`
  ADD COLUMN `matricule_prefixe` varchar(10) DEFAULT NULL AFTER `sigle`,
  ADD COLUMN `matricule_suffixe` varchar(5) DEFAULT NULL AFTER `matricule_prefixe`;
