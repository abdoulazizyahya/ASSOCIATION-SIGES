-- =====================================================================
--  ABZ_MBE — Migration v15
--  Correction de données : 12 libellés corrompus lors d'un import
--  historique (migration_v2, jamais rejouée avec le bon charset client).
--  Les octets non convertibles avaient été remplacés par des '?' littéraux
--  (ex. "1??re Ann??e" au lieu de "1ère Année") — perte irréversible côté
--  base, mais le texte original correct existe encore dans
--  SAVE/bd/migration_v2.sql et a servi de référence exacte ici (aucune
--  reconstruction approximative).
--  À exécuter via bd/run_migration_v15.php.
-- =====================================================================

UPDATE `filiere` SET `libelle` = 'Générale' WHERE `id` = 'GEN';

UPDATE `niveau` SET `libelle_niv` = '1ère Année'  WHERE `code_niveau` = '1A';
UPDATE `niveau` SET `libelle_niv` = '2ème Année'  WHERE `code_niveau` = '2A';
UPDATE `niveau` SET `libelle_niv` = '3ème Année'  WHERE `code_niveau` = '3A';
UPDATE `niveau` SET `libelle_niv` = '4ème Année'  WHERE `code_niveau` = '4A';
UPDATE `niveau` SET `libelle_niv` = 'Première'    WHERE `code_niveau` = '1ere';

UPDATE `serie` SET `libelle` = 'G1 – Comptabilité'          WHERE `id` = 1;
UPDATE `serie` SET `libelle` = 'G2 – Action commerciale'    WHERE `id` = 2;
UPDATE `serie` SET `libelle` = 'EEI – Electrotechnique'     WHERE `id` = 3;
UPDATE `serie` SET `libelle` = 'MAI – Maintenance industrielle' WHERE `id` = 4;
UPDATE `serie` SET `libelle` = 'GMC – Génie mécanique'      WHERE `id` = 5;
UPDATE `serie` SET `libelle` = 'Générale'                   WHERE `id` = 6;
