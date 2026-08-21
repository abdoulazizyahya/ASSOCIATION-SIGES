-- =====================================================================
--  ABZ_MBE — Migration v23
--  Reçus de paiement façon MANWI (reçu APEE, récapitulatif, reçu + fiche
--  de préinscription) :
--  - Nouveau signataire configurable `tresorier_apee` (2e signature du
--    reçu APEE, à côté de `president_apee` déjà existant).
--  - `obligation_frais.code_fixe` : identifie de façon fiable l'obligation
--    APEE et l'obligation INSCRIPTION (les boutons "Reçu APEE" et
--    "Fiche de préinscription" ont besoin de retrouver CETTE obligation
--    précise, un simple matching sur `libelle` serait cassé par un
--    renommage). Pas de contrainte UNIQUE : un seul `code_fixe='APEE'`
--    actif par année est une règle de gestion, pas une contrainte SQL
--    (validation applicative dans pages/paiements/obligations.php).
--  - `reglage_paiement` : montant du "FRAIS OPERATEUR" (fiche de
--    préinscription), configurable par année scolaire — même pattern que
--    `reglage_mention_bulletin` (bd/migration_v19.sql).
--  À exécuter via bd/run_migration_v23.php.
-- =====================================================================

INSERT IGNORE INTO `signature_titulaire` (`code`, `libelle`, `role_gestion`)
VALUES ('tresorier_apee', 'Trésorier de l\'APEE', 'INTENDANT');

ALTER TABLE `obligation_frais`
  ADD COLUMN `code_fixe` VARCHAR(20) NULL DEFAULT NULL AFTER `libelle`;

-- `numero_recu` était UNIQUE (un frais = un numéro, ancien modèle) — cela
-- empêche justement le regroupement voulu ici (plusieurs frais d'une même
-- soumission de `save.php` partagent désormais le même numero_recu, voir
-- Q1). Remplacé par un index simple (les requêtes filtrent toujours
-- `WHERE numero_recu = ?`, l'unicité d'un NOUVEAU numéro reste garantie de
-- façon applicative par generer_numero_recu() dans fonctions.php).
ALTER TABLE `paiement_frais`
  DROP INDEX `uk_numero_recu`,
  ADD INDEX `idx_numero_recu` (`numero_recu`);

CREATE TABLE IF NOT EXISTS `reglage_paiement` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_annee` INT UNSIGNED NOT NULL,
  `montant_frais_operateur` DECIMAL(10,2) NOT NULL DEFAULT 200.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reglage_paiement_annee` (`id_annee`),
  CONSTRAINT `fk_reglage_paiement_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
