-- =====================================================================
--  ABZ_MBE — Migration v21
--  Signature numérique : plusieurs signataires (chef d'établissement,
--  intendant, président APEE) + position/taille configurable par type
--  de document (glisser-déposer dans une fenêtre modale).
--  Remplace le mécanisme v20 (etablissement.signature, une seule image) :
--  la colonne v20 est conservée telle quelle (non supprimée) mais n'est
--  plus lue par le nouveau code — sa valeur est copiée une fois dans
--  signature_titulaire ci-dessous par bd/run_migration_v21.php.
--  À exécuter via bd/run_migration_v21.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `signature_titulaire` (
  `code` VARCHAR(30) NOT NULL,
  `libelle` VARCHAR(100) NOT NULL,
  `fichier` VARCHAR(255) NULL DEFAULT NULL,
  `role_gestion` VARCHAR(30) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `signature_position` (
  `type_document` VARCHAR(40) NOT NULL,
  `code_signature` VARCHAR(30) NOT NULL,
  `x_pct` DECIMAL(6,3) NOT NULL,
  `y_pct` DECIMAL(6,3) NOT NULL,
  `w_pct` DECIMAL(6,3) NOT NULL,
  `h_pct` DECIMAL(6,3) NULL DEFAULT NULL,
  PRIMARY KEY (`type_document`, `code_signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
