-- =====================================================================
--  jaynitaare_v2 — Migration v1
--  ⚠️ Numérotation repartie à 1 : les fichiers migration_v11.sql à
--  migration_v25.sql présents dans ce dossier sont des restes de la copie
--  initiale du code d'ABZ_MBE (voir prompt_continuite_jaynitaare_v2.md) —
--  ils ciblent le schéma ABZ_MBE (ex. `signature_titulaire`, absent de
--  jaynitaare_v2_bd) et n'ont jamais été exécutés ici. jaynitaare_v2 n'avait
--  jusqu'ici aucune migration versionnée (modifications ponctuelles en CLI
--  mysql, non conservées) — celle-ci est la première réelle du projet.
--
--  Objet : les groupes de compétences (`groupe_competence`) sont un
--  catalogue global (12 domaines Fr/An), mais tous ne s'appliquent pas à
--  tous les niveaux (ex. TIC ou Arabe/Éducation islamique pas forcément
--  enseignés en Maternelle). Nouvelle table de jonction pour associer/
--  activer/désactiver un groupe de compétences par niveau, sans toucher au
--  catalogue global ni aux tables héritées du legacy.
--
--  À exécuter via bd/run_migration_v1.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `groupe_competence_niveau` (
  `code_niveau`    VARCHAR(10) NOT NULL,
  `id_groupe_comp` INT NOT NULL,
  `actif`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code_niveau`, `id_groupe_comp`),
  KEY `id_groupe_comp` (`id_groupe_comp`),
  CONSTRAINT `fk_gcn_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gcn_groupe` FOREIGN KEY (`id_groupe_comp`) REFERENCES `groupe_competence` (`id_groupe_comp`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
