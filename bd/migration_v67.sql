-- =====================================================================
--  SIGES — Migration PRIMAIRE v67
--  Changement de chef d'établissement en cours d'année (26/10/2026)
-- =====================================================================
--  Une ligne par chef REMPLACÉ : son titre et sa signature (copie figée du
--  fichier), et le trimestre à partir duquel son successeur signe
--  (remplace_annee = 1re année de l'année scolaire, ex. 2026 pour 2026/2027 ;
--  remplace_trim = rang du trimestre). Un document d'une période antérieure
--  (bulletin, PV, relevé, tableau d'honneur…) prend la signature de ce chef ;
--  après, la signature actuelle (Paramètres). Le nom n'est jamais imprimé.
--  Voir chef_archive_document() dans noyau/fonctions.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `chef_historique` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `titre_fr` varchar(150) DEFAULT NULL,
  `titre_en` varchar(150) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `remplace_annee` smallint unsigned NOT NULL,
  `remplace_trim` tinyint unsigned NOT NULL DEFAULT 1,
  `note` varchar(150) DEFAULT NULL,
  `cree_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chef_remplace` (`remplace_annee`,`remplace_trim`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
