-- =====================================================================
--  jaynitaare_v2 — Migration v37 : notes zéro automatiques, éligibilité au
--  classement, annulation de trimestre (piste française)
-- =====================================================================
--  Demande explicite du 16/08/2026 :
--  1. Note zéro automatique : pour une compétence donnée d'un trimestre, si
--     au moins 50% des élèves de la classe ont déjà composé, un élève SANS
--     note pour cette compétence reçoit un 0 dans le calcul de sa moyenne
--     trimestrielle (au lieu d'être simplement ignoré comme avant) — sauf
--     si son trimestre est annulé (voir 3.).
--  2. Éligibilité au classement : un élève n'est classé (rang, comptabilisé
--     dans moy_classe/statistiques) que s'il a personnellement composé au
--     moins 50% des compétences de la classe ce trimestre. En dessous,
--     "Non classé", exclu du classement ET des statistiques/moyennes
--     générales, même si une moyenne a pu être calculée via 1.
--  3. Annulation de trimestre (nouvelle table `trimestre_annule`) : permet
--     d'exempter un élève d'un trimestre entier (ex. transfert tardif,
--     maladie longue) — sa moyenne annuelle est alors divisée par le nombre
--     de trimestres restants (pas toujours 3), et 1./2. ne s'appliquent pas
--     pour ce trimestre (aucune note zéro, non classé sans pénalité).
--  4. Moyenne annuelle : divisée par le nombre de trimestres de l'année
--     NON annulés pour cet élève (3, sauf annulation) — un trimestre non
--     annulé sans moyenne (élève non classé, ou aucune compétence composée)
--     compte pour 0 dans la somme mais reste dans le diviseur. Avant cette
--     migration, le diviseur était le nombre de trimestres ayant réellement
--     une moyenne (1 à 3) — comportement changé en conséquence dans
--     notes_apc.php::calculer_moyenne_annuelle_eleve(), aucun changement de
--     schéma nécessaire pour ce point précis (moyenne_annuelle.Nb_trim
--     existe déjà, sa signification change juste : "trimestres comptés au
--     dénominateur", plus "trimestres avec une moyenne").
-- =====================================================================

-- 1. Éligibilité au classement (persistée pour éviter de recalculer le
--    nombre de compétences composées à chaque lecture — classement_trimestre_
--    classe() est appelé très souvent, y compris en boucle pour un bulletin
--    de classe entière).
ALTER TABLE `moyenne_trimestre`
  ADD COLUMN `classable` tinyint(1) NOT NULL DEFAULT 1 AFTER `moy`;

-- 2. Annulation de trimestre par élève.
CREATE TABLE IF NOT EXISTS `trimestre_annule` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `id_eleve`       int unsigned NOT NULL,
  `id_trim`        int NOT NULL,
  `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `annule_le`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trim_annule_eleve_trim_annee` (`id_eleve`, `id_trim`, `val_annee`),
  KEY `id_trim` (`id_trim`),
  CONSTRAINT `fk_trim_annule_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trim_annule_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trim_annule_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trim_annule_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
