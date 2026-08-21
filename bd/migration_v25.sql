-- =====================================================================
--  ABZ_MBE — Migration v25
--  Ajoute "Secrétaire" au catalogue des signataires configurables
--  (signature_titulaire), demandé par l'établissement au même titre que
--  Censeur/SG/Intendant/Proviseur pour couvrir tous les documents du
--  système qui pourraient nécessiter sa signature.
--  À exécuter via bd/run_migration_v25.php.
-- =====================================================================

INSERT INTO `signature_titulaire` (`code`, `libelle`, `role_gestion`)
VALUES ('secretaire', 'Secrétaire', 'SECRETAIRE');
