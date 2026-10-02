-- =====================================================================
--  SIGES — Migration v60 : historique des mouvements de classe
-- =====================================================================
--  `inscrire` ne garde qu'UNE ligne par élève et par année (clé unique
--  id_eleve + val_annee) : un changement de classe en cours d'année
--  écrasait l'ancienne classe. Cette table journalise chaque mouvement
--  (inscription, changement de classe) avec sa date et l'auteur, année
--  après année — affiché dans « Historique scolaire » (pages/eleves/
--  voir.php). Demande du 02/10/2026.
--
--  Amorçage : chaque inscription existante devient un mouvement
--  « inscription » daté de sa Date_Inscrire (une seule fois : le test
--  NOT EXISTS rend la migration rejouable).
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `mouvement_classe` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `id_eleve`      int unsigned NOT NULL,
  `val_annee`     varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `classe_avant`  int DEFAULT NULL,
  `classe_apres`  int DEFAULT NULL,
  `type_mvt`      varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,   -- inscription | changement
  `date_mvt`      datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `auteur`        varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mvt_eleve` (`id_eleve`, `val_annee`, `date_mvt`),
  CONSTRAINT `fk_mvt_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mouvement_classe` (`id_eleve`, `val_annee`, `classe_avant`, `classe_apres`, `type_mvt`, `date_mvt`, `auteur`)
SELECT i.`id_eleve`, i.`val_annee`, NULL, i.`IDClasses`, 'inscription', i.`Date_Inscrire`, NULL
FROM `inscrire` i
WHERE NOT EXISTS (SELECT 1 FROM `mouvement_classe` m
                  WHERE m.`id_eleve` = i.`id_eleve` AND m.`val_annee` = i.`val_annee`);
