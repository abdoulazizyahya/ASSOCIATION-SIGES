-- =====================================================================
--  SIGES — Migration v56 : système de licence par école
-- =====================================================================
--  Protection d'usage par licence, PAR ÉCOLE (chaque base a son propre
--  cycle de vie, totalement indépendant — demande explicite du
--  13/09/2026) : à l'expiration, l'application bascule en lecture seule
--  (voir connexion.php::db_exec()) jusqu'à renouvellement. Seul le
--  PROPRIÉTAIRE de tout le système peut générer une clé de renouvellement
--  (bd/lib/licence.php::licence_generer_cle()) ; le Directeur ou le
--  Fondateur de l'école peuvent l'appliquer (pages/parametres/licence.php).
--
--  4 tables, toutes idempotentes via CREATE TABLE IF NOT EXISTS (pas
--  besoin du motif PREPARE/EXECUTE des migrations précédentes — réservé
--  aux ALTER TABLE ADD COLUMN sur une table déjà peuplée).

-- 1. Ligne courante (la plus RÉCENTE = active) — un INSERT par
--    renouvellement, jamais d'UPDATE des dates (l'historique complet
--    reste dans licence_historique ci-dessous).
CREATE TABLE IF NOT EXISTS `licence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_licence` varchar(255) DEFAULT NULL COMMENT 'clé ayant servi au dernier renouvellement, NULL si direct',
  `date_debut` date NOT NULL,
  `date_expiration` date NOT NULL,
  `statut` enum('active','suspendue','expiree') NOT NULL DEFAULT 'active',
  `derniere_modification_par` varchar(190) DEFAULT NULL,
  `date_derniere_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `signature` varchar(64) NOT NULL COMMENT 'HMAC-SHA256(date_expiration|cle_licence|statut, LICENCE_SECRET)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Historique : les 2 bornes AVANT/APRÈS + la méthode, jamais juste la
--    date de fin seule.
CREATE TABLE IF NOT EXISTS `licence_historique` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date_debut_avant` date DEFAULT NULL,
  `date_fin_avant` date DEFAULT NULL,
  `date_debut_apres` date NOT NULL,
  `date_fin_apres` date NOT NULL,
  `methode` enum('direct','cle') NOT NULL,
  `modifie_par` varchar(190) DEFAULT NULL,
  `date_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Singleton anti-brute-force (id=1 toujours présent — voir seed
--    ci-dessous, INSERT IGNORE idempotent, jamais de DELETE ensuite,
--    seulement des UPDATE : sinon les incréments suivants n'ont plus de
--    ligne à cibler).
CREATE TABLE IF NOT EXISTS `licence_securite` (
  `id` tinyint NOT NULL,
  `tentatives_echouees` int NOT NULL DEFAULT 0,
  `bloque_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO `licence_securite` (`id`, `tentatives_echouees`, `bloque_le`) VALUES (1, 0, NULL);

-- 4. Anti-rejeu : hash one-way de la clé (jamais la clé en clair) — une
--    clé qui y figure déjà ne peut plus jamais être ré-appliquée.
CREATE TABLE IF NOT EXISTS `licence_cles_utilisees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_hash` char(64) NOT NULL COMMENT 'sha256 de la clé normalisée',
  `utilisee_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_licence_cles_hash` (`cle_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
