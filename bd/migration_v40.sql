-- =====================================================================
--  jaynitaare_v2 — Migration v40 : absences en jours (plus en heures),
--  annulation d'évaluation (remplace l'annulation de trimestre)
-- =====================================================================
--  Demande explicite du 17/08/2026 :
--  1. Le module Absences (pages/absences/index.php, menu Discipline) et tout
--     ce qui en dépend (bulletins, conseil de classe, résultat annuel,
--     statistiques, mentions Avertissement/Blâme conduite) enregistraient un
--     NOMBRE D'HEURES d'absence (`absence.nbre_heure_non_jus`/`nbre_heure_
--     jus`) — remplacé par un nombre de JOURS, colonnes renommées en
--     conséquence (même type, même sémantique de comptage, seule l'unité
--     réelle change). Idem pour le seuil de délibération du conseil de
--     classe (`critere_conseil.heures_absence_max`, saisi par
--     l'établissement en heures jusqu'ici).
--  2. « Annulation de trimestre » (migration_v37, jamais utilisée en
--     production — 0 ligne réelle dans `trimestre_annule`) est remplacée par
--     une annulation à la granularité de l'ÉVALUATION (séquence), pas du
--     trimestre entier : `trimestre_annule` supprimée, nouvelle table
--     `evaluation_annulee` (id_eleve, id_seq) — voir notes_apc.php
--     ::annuler_evaluation_eleve()/evaluation_annulee_pour_eleve() et
--     pages/notes/annulation_evaluation.php.
-- =====================================================================

-- 1. Absences : heures -> jours (piste française uniquement, absence n'est
--    pas ventilée par piste — voir pages/absences/index.php).
ALTER TABLE `absence`
  CHANGE COLUMN `nbre_heure_non_jus` `nbre_jour_non_jus` int NOT NULL,
  CHANGE COLUMN `nbre_heure_jus`     `nbre_jour_jus`     int NOT NULL;

-- 2. Seuil de délibération (conseil de classe) : même changement d'unité,
--    sur les 2 tables (FR `critere_conseil` ET AR `critere_conseil_arabe`,
--    séparées pour ne jamais mélanger les 2 pistes dans une même table).
ALTER TABLE `critere_conseil`
  CHANGE COLUMN `heures_absence_max` `jours_absence_max` int NULL;
ALTER TABLE `critere_conseil_arabe`
  CHANGE COLUMN `heures_absence_max` `jours_absence_max` int NULL;

-- 3. Annulation de trimestre -> annulation d'évaluation.
DROP TABLE IF EXISTS `trimestre_annule`;

CREATE TABLE IF NOT EXISTS `evaluation_annulee` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `id_eleve`       int unsigned NOT NULL,
  `id_seq`         int NOT NULL,
  `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `annule_le`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eval_annule_eleve_seq_annee` (`id_eleve`, `id_seq`, `val_annee`),
  KEY `id_seq` (`id_seq`),
  CONSTRAINT `fk_eval_annule_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
