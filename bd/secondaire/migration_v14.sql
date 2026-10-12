-- =====================================================================
--  SIGES — Migration SECONDAIRE v14 : dossier de l'élève, validité des
--  documents enseignants (modules portés d'ABZ_MBE, 12/10/2026)
-- =====================================================================
--  dossier_eleve             : pièces jointes de l'élève (acte de naissance,
--                              carnet de vaccination…) — fiche élève.
--  reglage_validite_document : durée de validité des attestations et
--                              prises/reprises de service — Ressources
--                              humaines > Validité des documents.
--  Rejouable (IF NOT EXISTS).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `dossier_eleve` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `type_document` enum('acte_naissance','carnet_vaccination','bulletin','document_transfert','photo_4x4','autre') NOT NULL,
  `libelle` varchar(150) DEFAULT NULL,
  `fichier` varchar(255) NOT NULL,
  `date_ajout` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ajoute_par` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dossier_id_eleve` (`id_eleve`),
  KEY `fk_dossier_utilisateur` (`ajoute_par`),
  CONSTRAINT `fk_dossier_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dossier_utilisateur` FOREIGN KEY (`ajoute_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `reglage_validite_document` (
  `type_document` enum('attestation','prise','reprise') NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '0',
  `duree_valeur` int unsigned NOT NULL DEFAULT '3',
  `duree_unite` enum('jour','mois') NOT NULL DEFAULT 'mois',
  `maj_par` int unsigned DEFAULT NULL,
  `maj_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`type_document`),
  KEY `fk_rvd_maj_par` (`maj_par`),
  CONSTRAINT `fk_rvd_maj_par` FOREIGN KEY (`maj_par`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
