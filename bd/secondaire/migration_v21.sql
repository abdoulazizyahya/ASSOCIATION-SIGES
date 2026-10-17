-- =====================================================================
--  SIGES — Migration SECONDAIRE v21 : colonnes de paie de la fiche du personnel
-- =====================================================================
--  Constat du 17/10/2026 (mise en conformité de tout le secondaire) : les
--  colonnes de paie de `enseignant` (port du module Paie, 21/09/2026) figurent
--  dans le schéma de référence mais aucune migration ne les ajoutait aux écoles
--  créées avant : au Collège Islamique et au Collège Minhadjoul Mouslim, la fiche
--  du personnel affichait des avertissements et son enregistrement échouait.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'matricule_cnps');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `matricule_cnps` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'indice_grille');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `indice_grille` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'nb_enfants');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `nb_enfants` tinyint unsigned DEFAULT 0', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'nb_pers_charge');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `nb_pers_charge` tinyint unsigned DEFAULT 0', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'date_recrutement');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `date_recrutement` date DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'mode_paiement');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `mode_paiement` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'nom_banque');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `nom_banque` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;

SET @c := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'enseignant' AND column_name = 'compte_bancaire');
SET @s := IF(@c = 0, 'ALTER TABLE `enseignant` ADD COLUMN `compte_bancaire` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL', 'SELECT 1');
PREPARE st_v21 FROM @s; EXECUTE st_v21; DEALLOCATE PREPARE st_v21;
