-- =====================================================================
--  jaynitaare_v2 — Migration v45
--  1) Nouveau rôle COMPTABLE (Agent financier) dans `fonction` — accès
--     Scolarité (élèves en lecture/écriture sauf suppression et documents
--     d'identité, classes en lecture seule) + Finances complet, RH/Paramètres
--     en libre-service uniquement. Voir layout/header.php et les
--     exiger_role()/interdire_role() ajoutés dans les pages concernées.
--  2) Récupération de mot de passe par 2 questions secrètes (mot_de_passe_
--     oublie.php, configurer_securite.php) — même schéma qu'ABZ_MBE
--     (bd/migration_v11.sql), adapté aux noms de tables/colonnes de ce
--     projet (`user`/`id_user`, pas `utilisateur`/`id`).
--  À exécuter via bd/run_migration_v45.php (mysqli, idempotent).
-- =====================================================================

INSERT IGNORE INTO `fonction` (`id_fonction`) VALUES ('COMPTABLE');

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

CREATE TABLE IF NOT EXISTS `user_question_secrete` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`    INT NOT NULL,
  `id_question` INT UNSIGNED NOT NULL,
  `reponse_hash` VARCHAR(255) NOT NULL,
  `maj_le`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_question` (`id_user`, `id_question`),
  KEY `idx_uqs_question` (`id_question`),
  CONSTRAINT `fk_uqs_user` FOREIGN KEY (`id_user`)
      REFERENCES `user` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`)
      REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
