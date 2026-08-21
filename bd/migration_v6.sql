-- =====================================================================
--  jaynitaare_v2 — Migration v6
--  Suite de migration_v1.sql à v5.sql.
--
--  Objet (module Pédagogie ▸ Conseil de classe, adaptation du sous-menu
--  ABZ_MBE `pages/conseil_classe/`) : décision du conseil de classe par
--  élève/trimestre — Admis / Redouble / À statuer, avec observation libre.
--  ABZ_MBE a un moteur de règles auto-suggestion assez élaboré
--  (`decision_conseil` avec next_classe, seuils mémorisés côté client) —
--  volontairement simplifié ici : jaynitaare n'a pas encore ce niveau de
--  workflow, on part sur l'essentiel (décision + observation), à enrichir
--  plus tard si besoin réel exprimé par l'utilisateur.
--
--  À exécuter via bd/run_migration_v6.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `decision_conseil` (
  `id_eleve`     INT UNSIGNED NOT NULL,
  `classe`       INT NOT NULL,
  `id_trim`      INT NOT NULL,
  `val_annee`    VARCHAR(10) NOT NULL,
  `decision`     ENUM('Admis','Redouble','A statuer') NOT NULL DEFAULT 'A statuer',
  `observation`  VARCHAR(500) NULL,
  `modifie_le`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`, `classe`, `id_trim`, `val_annee`),
  KEY `classe` (`classe`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_decision_conseil_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
