-- =====================================================================
--  SIGES — Migration v59 : index (classe, val_annee) sur paiement_frais
-- =====================================================================
--  Tous les états financiers par classe (pages/finances/etat_classe.php,
--  pdf/finances_etat_classe.php, impayés, cas sociaux…) filtrent
--  paiement_frais sur classe ET val_annee : l'index composite évite de
--  parcourir tous les paiements de la classe, toutes années confondues.
--  Les index simples `classe` / `val_annee` existants restent en place
--  (utilisés par les clés étrangères).
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent (MySQL n'a pas de
--  CREATE INDEX IF NOT EXISTS : test préalable dans information_schema).
-- =====================================================================

SET @idx_existe := (SELECT COUNT(*) FROM information_schema.statistics
                    WHERE table_schema = DATABASE() AND table_name = 'paiement_frais'
                      AND index_name = 'idx_paiement_classe_annee');
SET @sql_v59 := IF(@idx_existe = 0,
    'ALTER TABLE `paiement_frais` ADD INDEX `idx_paiement_classe_annee` (`classe`, `val_annee`)',
    'SELECT 1');
PREPARE st_v59 FROM @sql_v59;
EXECUTE st_v59;
DEALLOCATE PREPARE st_v59;
