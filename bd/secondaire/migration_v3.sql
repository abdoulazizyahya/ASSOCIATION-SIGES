-- =====================================================================
--  SIGES — Migration SECONDAIRE v3 : couleurs personnalisables du bulletin
-- =====================================================================
--  Table pdf_couleur (identique au primaire, bd/migration_v30.sql) —
--  absente du schéma secondaire jusqu'ici : secondaire/pages/bulletins/
--  pdf.php utilisait des SetFillColor()/SetDrawColor() codés en dur.
--  5 rôles RÉELLEMENT utilisés par ce fichier (bulletin individuel) — voir
--  secondaire/pages/parametres/index.php, onglet « Couleurs bulletin ».
--  Valeurs par défaut = valeurs codées en dur qu'elles remplacent, pour ne
--  rien changer visuellement tant que personne n'y touche depuis les
--  réglages.
--
--  Convention : SQL pur, autosuffisant, idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pdf_couleur` (
  `cle` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `r` tinyint unsigned NOT NULL,
  `g` tinyint unsigned NOT NULL,
  `b` tinyint unsigned NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
('bandeau_titre', 'Bandeau titre (pilule d\'en-tête)', 219, 228, 245),
('bordure_marque', 'Bordure / couleur de marque', 26, 60, 107),
('entete_tableau_individuel', 'En-tête du tableau de compétences', 26, 60, 107),
('bandeau_section', 'Bandeaux de section (Disciplines/Travail/Profil/Résultats, matière, décision)', 216, 210, 248),
('ligne_echec', 'Surlignage moyenne insuffisante (< 10/20)', 255, 235, 235);
