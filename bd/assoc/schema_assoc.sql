-- =====================================================================
--  Base centrale « annuaire » de l'association (défaut : promeducam_assoc)
--  Annuaire des établissements + registres partagés au niveau association
--  (NIU élèves, personnel/affectations, comptes membres, suivi des
--  migrations par école, journal des actions).
--
--  Les données SCOLAIRES restent dans une base par école
--  (convention : promeducam_<slug du nom de l'établissement>).
--  Cette base ne contient QUE ce qui est transverse à l'association.
--
--  DDL pur et ré-exécutable (CREATE ... IF NOT EXISTS). Le peuplement
--  initial (école n°1 + 1er compte membre) est fait par
--  bd/assoc/installer.php.
--
--  {{DB_NAME_ASSOC}} est remplacé par la constante DB_NAME_ASSOC quand le
--  fichier est joué via bd/assoc/installer.php. Pour un import manuel
--  (mysql < schema_assoc.sql), remplacez-le d'abord par le nom voulu.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `{{DB_NAME_ASSOC}}`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `{{DB_NAME_ASSOC}}`;

-- ── Annuaire des établissements ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `etablissement` (
  `id`                   int          NOT NULL AUTO_INCREMENT,
  `code`                 varchar(20)  NOT NULL,                 -- ex. « EC1 » — porté par les URLs publiques ?ec=
  `sous_domaine`         varchar(63)  DEFAULT NULL,             -- ex. « ecole1 » (ecole1.assoc.cm) — NULL en LAN
  `db_name`              varchar(64)  NOT NULL,                 -- nom de la base MySQL de l'école
  `nom`                  varchar(150) NOT NULL,
  `sigle`                varchar(50)  DEFAULT NULL,
  `ville`                varchar(100) DEFAULT NULL,
  `logo`                 varchar(255) DEFAULT NULL,             -- nom de fichier sous assets/uploads/
  `couleur`              varchar(9)   DEFAULT NULL,             -- accent visuel (#RRGGBB)
  `verif_base_url`       varchar(255) DEFAULT NULL,             -- URL publique pour les QR de vérification (par école)
  `verif_cle_privee_pem` text         DEFAULT NULL,             -- clé ECDSA P-256 de signature offline (par école)
  `actif`                tinyint(1)   NOT NULL DEFAULT 1,
  `cree_le`              datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_code`         (`code`),
  UNIQUE KEY `uq_db_name`      (`db_name`),
  UNIQUE KEY `uq_sous_domaine` (`sous_domaine`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Comptes des membres de l'association ─────────────────────────────
CREATE TABLE IF NOT EXISTS `membre` (
  `id`           int          NOT NULL AUTO_INCREMENT,
  `login`        varchar(50)  NOT NULL,
  `pwd_hash`     varchar(255) NOT NULL,
  `nom`          varchar(100) NOT NULL,
  `prenom`       varchar(100) DEFAULT NULL,
  `email`        varchar(150) DEFAULT NULL,
  `actif`        tinyint(1)   NOT NULL DEFAULT 1,
  `totp_secret`  varchar(64)  DEFAULT NULL,          -- secret Base32 de la double authentification
  `totp_actif`   tinyint(1)   NOT NULL DEFAULT 0,    -- 2FA exigée à la connexion
  `cree_le`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Tentatives de connexion échouées (limitation de débit) ──────────
CREATE TABLE IF NOT EXISTS `login_echec` (
  `id`     bigint      NOT NULL AUTO_INCREMENT,
  `login`  varchar(50) DEFAULT NULL,
  `ip`     varchar(45) DEFAULT NULL,
  `date`   datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_login` (`login`,`date`),
  KEY `k_ip`    (`ip`,`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Droits d'un membre sur les écoles ───────────────────────────────
--  id_etablissement NULL = toutes les écoles.
--  plein_acces 0 = visite en LECTURE SEULE (défaut) ; 1 = écriture autorisée.
CREATE TABLE IF NOT EXISTS `membre_acces` (
  `id`               int NOT NULL AUTO_INCREMENT,
  `id_membre`        int NOT NULL,
  `id_etablissement` int DEFAULT NULL,
  `plein_acces`      tinyint(1) NOT NULL DEFAULT 0,
  `actif`            tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `k_membre` (`id_membre`),
  KEY `k_etab`   (`id_etablissement`),
  CONSTRAINT `fk_acces_membre` FOREIGN KEY (`id_membre`)        REFERENCES `membre` (`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_acces_etab`   FOREIGN KEY (`id_etablissement`) REFERENCES `etablissement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registre NIU : identité élève, stable à vie, indépendante de l'école ──
--  Le NIU est frappé UNE fois au niveau association (déduplication sur
--  nom/prénom/date de naissance) puis suit l'élève quelle que soit l'école.
CREATE TABLE IF NOT EXISTS `eleve_niu` (
  `niu`             varchar(30)  NOT NULL,
  `nom`             varchar(150) DEFAULT NULL,
  `prenom`          varchar(150) DEFAULT NULL,
  `date_naissance`  varchar(12)  DEFAULT NULL,
  `sexe`            varchar(12)  DEFAULT NULL,
  `lieu_naissance`  varchar(150) DEFAULT NULL,
  `nom_pere`        varchar(150) DEFAULT NULL,
  `nom_mere`        varchar(150) DEFAULT NULL,
  `id_etab_origine` int DEFAULT NULL,
  `id_etab_courant` int DEFAULT NULL,
  `statut`          enum('reserve','actif','sorti') NOT NULL DEFAULT 'reserve',
  `cree_le`         datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cree_par`        varchar(100) DEFAULT NULL,
  PRIMARY KEY (`niu`),
  KEY `k_identite`      (`nom`,`prenom`,`date_naissance`),
  KEY `k_etab_courant`  (`id_etab_courant`),
  CONSTRAINT `fk_niu_origine` FOREIGN KEY (`id_etab_origine`) REFERENCES `etablissement` (`id`),
  CONSTRAINT `fk_niu_courant` FOREIGN KEY (`id_etab_courant`) REFERENCES `etablissement` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `eleve_niu_mouvement` (
  `id`             bigint NOT NULL AUTO_INCREMENT,
  `niu`            varchar(30) NOT NULL,
  `id_etab_source` int DEFAULT NULL,
  `id_etab_cible`  int DEFAULT NULL,
  `type`           enum('creation','inscription','transfert','sortie','reintegration') NOT NULL,
  `date`           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `motif`          varchar(255) DEFAULT NULL,
  `par`            varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_niu` (`niu`),
  CONSTRAINT `fk_mvt_niu` FOREIGN KEY (`niu`) REFERENCES `eleve_niu` (`niu`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registre du personnel : identité agent, matricule stable ─────────
--  Source de vérité de l'identité ; chaque base école garde ses propres
--  lignes `enseignant`/`user` reliées par ce matricule.
CREATE TABLE IF NOT EXISTS `personnel` (
  `matricule`      varchar(30)  NOT NULL,
  `nom`            varchar(150) NOT NULL,
  `prenom`         varchar(100) DEFAULT NULL,
  `date_naissance` varchar(12)  DEFAULT NULL,
  `sexe`           varchar(12)  DEFAULT NULL,
  `tel`            varchar(50)  DEFAULT NULL,
  `email`          varchar(200) DEFAULT NULL,
  `diplome`        varchar(150) DEFAULT NULL,
  `statut`         varchar(20)  NOT NULL DEFAULT 'actif',
  `cree_le`        datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`matricule`),
  KEY `k_identite` (`nom`,`prenom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `personnel_affectation` (
  `id`                  bigint NOT NULL AUTO_INCREMENT,
  `matricule`           varchar(30) NOT NULL,
  `id_etablissement`    int NOT NULL,
  `fonction`            varchar(50) DEFAULT NULL,  -- DIRECTEUR | FONDATEUR | ENSEIGNANT | SECRETAIRE | COMPTABLE
  `matricule_ens_local` varchar(30) DEFAULT NULL,  -- enseignant.matricule_ens dans la base école
  `id_user_local`       int DEFAULT NULL,          -- user.id_user dans la base école
  `date_debut`          date DEFAULT NULL,
  `date_fin`            date DEFAULT NULL,
  `actif`               tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `k_matricule` (`matricule`),
  KEY `k_etab`      (`id_etablissement`),
  CONSTRAINT `fk_aff_personnel` FOREIGN KEY (`matricule`)        REFERENCES `personnel` (`matricule`) ON DELETE CASCADE,
  CONSTRAINT `fk_aff_etab`      FOREIGN KEY (`id_etablissement`) REFERENCES `etablissement` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Suivi des migrations de schéma appliquées à chaque base école ────
CREATE TABLE IF NOT EXISTS `schema_version_etab` (
  `id_etablissement` int NOT NULL,
  `version`          int NOT NULL DEFAULT 0,
  `maj_le`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_etablissement`),
  CONSTRAINT `fk_ver_etab` FOREIGN KEY (`id_etablissement`) REFERENCES `etablissement` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Journal des actions des membres (traçabilité des « visites ») ────
CREATE TABLE IF NOT EXISTS `journal_action` (
  `id`               bigint NOT NULL AUTO_INCREMENT,
  `id_membre`        int DEFAULT NULL,
  `id_etablissement` int DEFAULT NULL,
  `action`           varchar(50)  NOT NULL,
  `cible`            varchar(255) DEFAULT NULL,
  `date`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip`               varchar(45)  DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_membre` (`id_membre`),
  KEY `k_date`   (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pool de bases vides pré-créées (hébergement mutualisé) ───────────
--  En mutualisé cPanel, le compte MySQL ne peut pas faire CREATE DATABASE
--  depuis PHP. On crée donc À L'AVANCE des bases vides dans le cPanel, on
--  les enregistre ici (bd/assoc/pool_enregistrer.php), et la création
--  d'une école (creer_etablissement, si ECOLE_POOL_ACTIF) en consomme une :
--  elle y charge le schéma de référence puis l'inscrit à l'annuaire.
--  Inutilisé en LAN / serveur dédié (CREATE DATABASE direct).
CREATE TABLE IF NOT EXISTS `bd_pool` (
  `db_name`          varchar(64) NOT NULL,                    -- nom EXACT de la base MySQL vide (ex. promeduca_pool01)
  `etat`             enum('libre','consomme') NOT NULL DEFAULT 'libre',
  `id_etablissement` int         DEFAULT NULL,                -- école qui l'a consommée
  `cree_le`          datetime    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `consomme_le`      datetime    DEFAULT NULL,
  PRIMARY KEY (`db_name`),
  KEY `k_etat` (`etat`),
  CONSTRAINT `fk_pool_etab` FOREIGN KEY (`id_etablissement`) REFERENCES `etablissement` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
