-- =====================================================================
--  jaynitaare_v2 — Migration v30 : couleurs personnalisables du bulletin PDF
-- =====================================================================
--  Les couleurs de fond (SetFillColor) des bulletins PDF (trimestriel/
--  annuel, FR/AR) étaient codées en dur dans le code (pdf/bulletin_*.php).
--  Sur les ~21 appels SetFillColor() du seul bulletin trimestriel FR,
--  seules 6 couleurs distinctes sont réellement utilisées, réparties sur
--  les 4 générateurs — cette table les rend configurables via une
--  interface (pages/parametres/index.php, onglet Couleurs) au lieu de
--  devoir éditer le code pour changer un rendu.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `pdf_couleur` (
  `cle`     varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `r`       tinyint unsigned NOT NULL,
  `g`       tinyint unsigned NOT NULL,
  `b`       tinyint unsigned NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valeurs de départ = couleurs actuellement codées en dur (aucun
-- changement visuel tant que personne ne les modifie via l'interface).
INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
('groupe_competence', "En-tête des groupes de compétences",                       45, 231, 218),
('ligne_alternee',    'Lignes alternées (tableau de notes)',                      227, 227, 227),
('ligne_rayee',       'Rayures de lignes (bulletin trimestriel FR)',              249, 249, 249),
('entete_section',    'En-têtes de section (Discipline/Travail/Profil, FR trim.)', 65, 165, 165),
('cellule_resultat',  'Cellules de résultat (Moyenne/Rang/Appréciation)',         228, 228, 228),
('entete_bleu',       'En-têtes (bulletin annuel + bulletins arabes)',            146, 220, 255);
