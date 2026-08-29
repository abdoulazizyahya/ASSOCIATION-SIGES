-- =====================================================================
--  jaynitaare_v2 — Migration v46
--  Piste arabe : Matières par niveau + Barème (Oral/Écrit/Pratique).
--  Additif : `composer_sequence_arabe.note` reste la source pour toute
--  matière sans barème (voir notes_apc_arabe.php::note_matiere_sequence_arabe()).
--
--  1) matiere_arabe.id_groupe — groupe intrinsèque à la matière (backfill
--     depuis classe_matiere_arabe).
--  2) matiere_niveau_arabe — matières (+ ordre) assignées à un niveau.
--  3) discipline_arabe — barème Oral/Écrit/Pratique par classe/matière/année.
--  4) composer_sequence_arabe — colonnes note_orale/ecrite/pratique/
--     total_points, nullables.
--  À exécuter via bd/run_migration_v46.php (idempotent).
-- =====================================================================

ALTER TABLE `matiere_arabe`
  ADD COLUMN `id_groupe` INT NULL AFTER `matiere_ar`,
  ADD CONSTRAINT `fk_matarabe_groupe` FOREIGN KEY (`id_groupe`)
      REFERENCES `groupe_matiere_arabe` (`id_groupe`) ON DELETE SET NULL ON UPDATE CASCADE;

UPDATE `matiere_arabe` m
  SET m.id_groupe = (SELECT MIN(cma.id_groupe) FROM `classe_matiere_arabe` cma WHERE cma.id_mat = m.id_mat)
  WHERE m.id_groupe IS NULL;

CREATE TABLE IF NOT EXISTS `matiere_niveau_arabe` (
  `code_niveau` VARCHAR(10) NOT NULL,
  `id_mat`      INT NOT NULL,
  `ordre`       INT NOT NULL DEFAULT 1,
  `actif`       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code_niveau`, `id_mat`),
  CONSTRAINT `fk_matniv_niveau` FOREIGN KEY (`code_niveau`)
      REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_matniv_mat` FOREIGN KEY (`id_mat`)
      REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `discipline_arabe` (
  `IDClasses`    INT NOT NULL,
  `id_mat`       INT NOT NULL,
  `annee_scol`   VARCHAR(10) NOT NULL,
  `orale`        FLOAT NOT NULL DEFAULT 0,
  `ecrite`       FLOAT NOT NULL DEFAULT 0,
  `pratique`     FLOAT NOT NULL DEFAULT 0,
  `total_points` FLOAT NOT NULL DEFAULT 0,
  `actif`        TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`IDClasses`, `id_mat`, `annee_scol`),
  CONSTRAINT `fk_discar_classe` FOREIGN KEY (`IDClasses`)
      REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_discar_mat` FOREIGN KEY (`id_mat`)
      REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `composer_sequence_arabe`
  ADD COLUMN `note_orale` FLOAT NULL,
  ADD COLUMN `note_ecrite` FLOAT NULL,
  ADD COLUMN `note_pratique` FLOAT NULL,
  ADD COLUMN `note_total_points` FLOAT NULL;
