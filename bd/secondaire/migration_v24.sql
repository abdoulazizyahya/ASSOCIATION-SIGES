-- =====================================================================
--  SIGES — Migration SECONDAIRE v24
--  Classes mixtes : liaison matière ↔ série (30/10/2026)
-- =====================================================================
--  Une classe peut regrouper des élèves de plusieurs séries / LV2
--  (inscription.id_serie, ex. 4ème ALL/ESP/ITA). Une ligne ici RÉSERVE une
--  matière de la classe aux élèves des séries cochées (ex. Espagnol → ESP).
--  Matière sans ligne = commune à toute la classe (comportement d'avant).
--  Un élève hors de ces séries (ou sans série) ne suit pas la matière : pas
--  de ligne au bulletin, pas de zéro automatique, et le seuil « absent
--  majoritaire = 0 » est calculé sur l'effectif du groupe qui la suit.
--  Voir sec_series_charger() dans noyau/fonctions.php.
--  Idempotente : rejouable sans erreur.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `discipline_serie` (
  `IDClasses` int NOT NULL,
  `id_mat` int NOT NULL,
  `id_serie` int unsigned NOT NULL,
  PRIMARY KEY (`IDClasses`,`id_mat`,`id_serie`),
  KEY `idx_disc_serie_serie` (`id_serie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
