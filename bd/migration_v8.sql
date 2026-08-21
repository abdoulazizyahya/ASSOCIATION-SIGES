-- =====================================================================
--  jaynitaare_v2 — Migration v8
--  Suite de migration_v1.sql à v7.sql.
--
--  Objet (Conseil de classe — parité complète avec ABZ_MBE, demande
--  explicite utilisateur « tous les sous-menu ... identiques, meme forme,
--  affichage, dispositions ») :
--   1) `decision_conseil` (trimestriel, migration_v6) était volontairement
--      simplifiée avec un ENUM 3 valeurs (Admis/Redouble/A statuer) — pour
--      un vrai conseil trimestriel façon ABZ_MBE il faut les 6 mentions
--      réelles (Félicitations/Encouragements/Tableau d'honneur/
--      Avertissement (travail)/Blâme (travail)/RAS). ENUM -> VARCHAR(30).
--      Table vide (0 lignes) au moment de cette migration, aucune donnée
--      à convertir.
--   2) `decision_conseil_annuel` n'existait pas du tout — ABZ_MBE distingue
--      décision trimestrielle (mention) et décision annuelle (promotion +
--      classe suivante), stockées différemment (type ENUM + id_trim
--      sentinelle côté ABZ_MBE). jaynitaare suit sa propre convention déjà
--      en place (tables séparées trimestre/annuel, ex. moyenne_trimestre
--      vs moyenne_annuelle) : nouvelle table dédiée plutôt qu'un sentinel
--      fragile sur id_trim (qui a une contrainte FK NOT NULL vers
--      `trimestre`, incompatible avec un sentinel 0).
--   3) `critere_conseil` n'existait pas — mémorisation des seuils de
--      décision automatique par classe/année (Tableau d'honneur/
--      Encouragement/Félicitation côté trimestriel ; moyenne d'admission/
--      d'exclusion, heures d'absence max, jours d'exclusion max côté
--      annuel), comme ABZ_MBE.
--
--  À exécuter via bd/run_migration_v8.php.
-- =====================================================================

ALTER TABLE `decision_conseil`
  MODIFY COLUMN `decision` VARCHAR(30) NOT NULL DEFAULT 'RAS';

CREATE TABLE IF NOT EXISTS `decision_conseil_annuel` (
  `id_eleve`     INT UNSIGNED NOT NULL,
  `classe`       INT NOT NULL,
  `val_annee`    VARCHAR(10) NOT NULL,
  `decision`     VARCHAR(30) NOT NULL DEFAULT '',
  `next_classe`  INT NULL,
  `observation`  VARCHAR(500) NULL,
  `modifie_le`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`, `classe`, `val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_decision_annuel_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_next_classe` FOREIGN KEY (`next_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `critere_conseil` (
  `id_classe`              INT NOT NULL,
  `val_annee`               VARCHAR(10) NOT NULL,
  `moyenne_admission`       DECIMAL(4,2) NULL,
  `moyenne_exclusion`       DECIMAL(4,2) NULL,
  `heures_absence_max`      INT NULL,
  `jours_exclusion_max`     INT NULL,
  `seuil_tableau_honneur`   DECIMAL(4,2) NULL,
  `seuil_encouragement`     DECIMAL(4,2) NULL,
  `seuil_felicitation`      DECIMAL(4,2) NULL,
  PRIMARY KEY (`id_classe`, `val_annee`),
  CONSTRAINT `fk_critere_conseil_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_critere_conseil_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
