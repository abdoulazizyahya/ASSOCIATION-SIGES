-- =====================================================================
--  SIGES — Migration SECONDAIRE v26
--  Fiche élève comme au primaire : parents, tuteurs, informations
--  complémentaires (30/10/2026)
-- =====================================================================
--  1. tuteur.adresse : le père, la mère et les autres tuteurs sont des
--     lignes de `tuteur` (lien « Père », « Mère », « Oncle »…) — c'est déjà
--     là que le certificat de scolarité, la fiche PDF, les cartes et les
--     bulletins les lisent. On ajoute l'adresse, comme au primaire.
--  2. info_supplementaires : scolarité antérieure, santé, handicap,
--     réfugié — mêmes champs que le primaire (sans « cas social », lié aux
--     finances du primaire).
--  3. format_carte : « × » des libellés perdu à l'import (« 8,56 ? 5,4 cm »).
--  Idempotente : rejouable sans erreur.
-- =====================================================================

ALTER TABLE `tuteur` ADD COLUMN `adresse` varchar(200) DEFAULT NULL AFTER `profession`;

CREATE TABLE IF NOT EXISTS `info_supplementaires` (
  `id_eleve` int unsigned NOT NULL,
  `dernier_etab` varchar(250) DEFAULT NULL,
  `derniere_classe` varchar(200) DEFAULT NULL,
  `date_rec` date DEFAULT NULL,
  `antecedent_med` mediumtext,
  `frequence` varchar(250) DEFAULT NULL,
  `autres_infos` mediumtext,
  `handicap` varchar(5) NOT NULL DEFAULT '',
  `nature_handicap` varchar(200) NOT NULL DEFAULT '',
  `refugie` varchar(5) NOT NULL DEFAULT '',
  `type_refugie` varchar(200) NOT NULL DEFAULT '',
  `maj_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`),
  CONSTRAINT `fk_info_suppl_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `format_carte` SET `libelle` = REPLACE(`libelle`, ' ? ', ' × ') WHERE `libelle` LIKE '% ? %';
