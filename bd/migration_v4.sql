-- =====================================================================
--  jaynitaare_v2 — Migration v4
--  Suite de migration_v1.sql / v2.sql / v3.sql.
--
--  Objet (demande utilisateur du 10/08/2026) : dernier niveau de la
--  hiérarchie administrative camerounaise, sous `departement` (déjà présent
--  et correctement peuplé : 10 régions / 58 départements réels). Adapté aux
--  conventions de nommage déjà en place dans ce projet (code_x/intitule_x,
--  comme region.id_region/intitule_region et departement.code_depart/
--  intitule_depart/code_region) plutôt qu'au schéma générique id/nom
--  proposé dans la demande — cohérence avec l'existant.
--
--  ⚠️ Table volontairement laissée VIDE : la liste officielle des 360
--  arrondissements doit être fournie par l'utilisateur (CSV/Excel) et
--  importée via bd/sync_arrondissements.php — jamais saisie de mémoire par
--  l'IA sur un référentiel administratif aussi précis.
--
--  UNIQUE KEY (code_depart, intitule_arrond) : empêche les doublons exacts
--  lors d'imports répétés (le script de synchronisation s'appuie dessus
--  pour être idempotent).
--
--  À exécuter via bd/run_migration_v4.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `arrondissement` (
  `code_arrond`     INT NOT NULL AUTO_INCREMENT,
  `intitule_arrond` VARCHAR(100) NOT NULL,
  `code_depart`     INT NOT NULL,
  PRIMARY KEY (`code_arrond`),
  UNIQUE KEY `uk_arrond_depart` (`code_depart`, `intitule_arrond`),
  KEY `code_depart` (`code_depart`),
  CONSTRAINT `arrondissement_ibfk_1` FOREIGN KEY (`code_depart`) REFERENCES `departement` (`code_depart`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
