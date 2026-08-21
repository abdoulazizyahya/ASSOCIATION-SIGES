-- =====================================================================
--  jaynitaare_v2 — Migration v33 : module Gestion des dépenses
-- =====================================================================
--  Demande explicite du 15/08/2026 : le menu Finances ne gérait que les
--  ENCAISSEMENTS (versements des élèves). Nouveau module séparé pour les
--  DÉCAISSEMENTS (dépenses de l'établissement), classées par catégorie —
--  les dépenses sont effectuées à partir des montants encaissés (versements
--  élèves), le solde disponible = SUM(paiement_frais.montant_paiement) -
--  SUM(depense.montant), calculé à la volée (pas de colonne "solde" stockée
--  qui pourrait diverger).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `categorie_depense` (
  `id_categorie` int NOT NULL AUTO_INCREMENT,
  `libelle`      varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description`  varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_categorie`),
  UNIQUE KEY `uk_categorie_depense_libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `depense` (
  `id_depense`    int NOT NULL AUTO_INCREMENT,
  `id_categorie`  int NOT NULL,
  `libelle`       varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant`       decimal(12,2) NOT NULL,
  `date_depense`  date NOT NULL,
  `val_annee`     varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `beneficiaire`  varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observation`   varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_depense`),
  KEY `id_categorie` (`id_categorie`),
  KEY `val_annee` (`val_annee`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `date_depense` (`date_depense`),
  CONSTRAINT `fk_depense_categorie` FOREIGN KEY (`id_categorie`) REFERENCES `categorie_depense` (`id_categorie`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_depense_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_depense_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catégories de départ usuelles pour un établissement scolaire — modifiables/
-- supprimables ensuite via l'écran Catégories (aucune n'est codée en dur
-- ailleurs, juste un point de départ pratique).
INSERT IGNORE INTO `categorie_depense` (`libelle`, `description`) VALUES
('Salaires',            'Salaires et primes du personnel'),
('Fournitures scolaires','Matériel et fournitures pédagogiques'),
('Entretien & réparations', 'Maintenance des locaux et équipements'),
('Eau & Électricité',   'Factures d''eau et d''électricité'),
('Transport',           'Frais de transport et carburant'),
('Administration',      'Frais administratifs divers'),
('Autres',              'Dépenses non classées ailleurs');
