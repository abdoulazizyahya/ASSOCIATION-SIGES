-- =====================================================================
--  ABZ_MBE — Migration v11
--  Hashage des mots de passe (bcrypt) + récupération par 2 questions
--  secrètes choisies dans un catalogue prédéfini.
--  Ajoute aussi la colonne `email` manquante sur `utilisateur` (référencée
--  par pages/utilisateurs/form.php et profil.php mais jamais créée par
--  aucune migration présente sur disque — bug bloquant corrigé ici).
--  À exécuter via bd/run_migration_v11.php (le hashage des mots de passe
--  existants ne peut se faire qu'en PHP, pas en SQL pur).
-- =====================================================================

ALTER TABLE `utilisateur` ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `question_secrete` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle` VARCHAR(255) NOT NULL,
  `actif`   TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `question_secrete` (`libelle`) VALUES
  ('Quel est le nom de jeune fille de votre mère ?'),
  ('Quelle est votre ville de naissance ?'),
  ('Quel est le nom de votre premier animal de compagnie ?'),
  ('Quel est le nom de votre meilleur ami d''enfance ?'),
  ('Quel est le nom de votre école primaire ?'),
  ('Quel est votre plat préféré ?'),
  ('Quel est le prénom de votre grand-père paternel ?'),
  ('Quel est le nom de votre premier employeur ?'),
  ('Quelle est votre couleur préférée ?'),
  ('Quel surnom vous donnait-on enfant ?');

CREATE TABLE IF NOT EXISTS `utilisateur_question_secrete` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_utilisateur` INT UNSIGNED NOT NULL,
  `id_question`    INT UNSIGNED NOT NULL,
  `reponse_hash`   VARCHAR(255) NOT NULL,
  `maj_le`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_question` (`id_utilisateur`, `id_question`),
  KEY `idx_uqs_question` (`id_question`),
  CONSTRAINT `fk_uqs_utilisateur` FOREIGN KEY (`id_utilisateur`)
      REFERENCES `utilisateur` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`)
      REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
