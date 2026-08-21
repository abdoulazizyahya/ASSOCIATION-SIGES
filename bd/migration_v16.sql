-- =====================================================================
--  ABZ_MBE — Migration v16
--  Portée des frais exigibles : un frais peut concerner tout
--  l'établissement (ex. APEE, Photo, Visite médicale, Livret médical),
--  tout un cycle (ex. Inscription : 7500F au 1er Cycle, 9500F au 2nd
--  Cycle), ou un seul niveau (ex. BAC = Terminale uniquement, Probatoire
--  = Première uniquement — comportement d'origine, conservé par défaut).
--  Pas de table `cycle` dédiée dans ABZ_MBE : niveau.id_cycle est un
--  texte libre ('1er Cycle'/'2nd Cycle'), obligation_frais.id_cycle
--  suit la même convention (pas de FK possible).
--  À exécuter via bd/run_migration_v16.php.
-- =====================================================================

ALTER TABLE `obligation_frais`
  ADD COLUMN `portee` ENUM('etablissement','cycle','niveau') NOT NULL DEFAULT 'niveau' AFTER `libelle`,
  ADD COLUMN `id_cycle` VARCHAR(20) NULL DEFAULT NULL AFTER `code_niveau`,
  MODIFY COLUMN `code_niveau` VARCHAR(25) NULL DEFAULT NULL;

ALTER TABLE `obligation_frais`
  DROP INDEX `uk_obligation`,
  ADD UNIQUE KEY `uk_obligation` (`libelle`, `portee`, `code_niveau`, `id_cycle`, `id_annee`);
