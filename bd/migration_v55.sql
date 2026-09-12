-- =====================================================================
--  SIGES — Migration v55 : numéro de reçu unique par versement (groupe)
-- =====================================================================
--  Un versement « cotisation » peut être réparti automatiquement sur
--  plusieurs frais (obligations) : chaque frais touché crée sa propre
--  ligne `paiement_frais` (jamais une seule ligne « mélangée »). Jusqu'ici
--  chaque ligne générait son propre numéro de reçu (finances_numero_recu()
--  dérivé de id_pay), donc un même versement du parent pouvait afficher
--  plusieurs numéros différents. Demande explicite : un versement = un
--  seul numéro de reçu, même réparti sur plusieurs frais.
--
--   • paiement_frais.id_versement : identifiant de groupe, NULL pour les
--     lignes historiques (pas de régression : COALESCE(id_versement,
--     id_pay) reste le numéro de groupe effectif, donc une ligne isolée
--     non repartie garde son propre id_pay comme numéro).
--
--  Convention v51+ : SQL pur, idempotent, appliqué par
--  bd/assoc/migrer_toutes_ecoles.php à chaque base école.

SET @has_idv := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'paiement_frais' AND column_name = 'id_versement');
SET @sql := IF(@has_idv = 0,
    'ALTER TABLE `paiement_frais` ADD COLUMN `id_versement` int(11) DEFAULT NULL AFTER `id_pay`',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'paiement_frais' AND index_name = 'idx_paiement_frais_id_versement');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE `paiement_frais` ADD INDEX `idx_paiement_frais_id_versement` (`id_versement`)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
