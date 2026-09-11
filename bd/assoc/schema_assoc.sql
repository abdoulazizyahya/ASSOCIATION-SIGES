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
  `niu_sigle`            varchar(3)   DEFAULT NULL,             -- code 3 lettres de l'école dans le NIU (défaut : 3 premières lettres du sigle)
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
  `tel`          varchar(30)  DEFAULT NULL,          -- téléphone (récupération de mot de passe)
  `actif`        tinyint(1)   NOT NULL DEFAULT 1,
  `proprietaire` tinyint(1)   NOT NULL DEFAULT 0,    -- compte fondateur : seul habilité à créer/retirer d'autres superadmins
  `totp_secret`  varchar(64)  DEFAULT NULL,          -- secret Base32 de la double authentification
  `totp_actif`   tinyint(1)   NOT NULL DEFAULT 0,    -- 2FA exigée à la connexion
  `cree_le`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login` (`login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Questions secrètes d'un membre (récupération de mot de passe) ───
--  Voie de récupération complémentaire à e-mail + téléphone
--  (association/securite.php, association/mot_de_passe_oublie.php).
--  numero = 1 ou 2 ; question = libellé choisi ; reponse_hash = password_hash
--  de la réponse normalisée (minuscules + espaces réduits).
CREATE TABLE IF NOT EXISTS `membre_question_secrete` (
  `id_membre`    int          NOT NULL,
  `numero`       tinyint(1)   NOT NULL,
  `question`     varchar(160) NOT NULL,
  `reponse_hash` varchar(255) NOT NULL,
  `maj_le`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_membre`,`numero`),
  CONSTRAINT `fk_mqs_membre` FOREIGN KEY (`id_membre`) REFERENCES `membre` (`id`) ON DELETE CASCADE
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

-- ── Journal d'audit unifié (connexions + actions de TOUS les comptes) ─
--  Remplace journal_action : couvre aussi les comptes d'école (directeur,
--  fondateur, enseignant, comptable) et enregistre appareil + localisation.
--  Voir bd/lib/audit.php (audit_log / audit_ua / audit_geo).
CREATE TABLE IF NOT EXISTS `journal_audit` (
  `id`               bigint       NOT NULL AUTO_INCREMENT,
  `date`             datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `evenement`        varchar(20)  NOT NULL,                 -- connexion | connexion_echec | deconnexion | action
  `action`           varchar(60)  DEFAULT NULL,            -- slug quand evenement='action'
  `cible`            varchar(255) DEFAULT NULL,
  `acteur_type`      enum('membre','user','inconnu') NOT NULL DEFAULT 'inconnu',
  `acteur_id`        int          DEFAULT NULL,
  `acteur_login`     varchar(60)  DEFAULT NULL,
  `acteur_nom`       varchar(120) DEFAULT NULL,
  `role`             varchar(30)  DEFAULT NULL,            -- DIRECTEUR, ENSEIGNANT, COMPTABLE, FONDATEUR, SUPERADMIN, MEMBRE
  `id_etablissement` int          DEFAULT NULL,
  `ip`               varchar(45)  DEFAULT NULL,
  `ua_navigateur`    varchar(60)  DEFAULT NULL,
  `ua_os`            varchar(60)  DEFAULT NULL,
  `ua_appareil`      varchar(12)  DEFAULT NULL,            -- ordinateur | tablette | mobile | bot
  `ua_brut`          varchar(400) DEFAULT NULL,
  `geo_pays`         varchar(60)  DEFAULT NULL,
  `geo_region`       varchar(80)  DEFAULT NULL,
  `geo_ville`        varchar(80)  DEFAULT NULL,
  `geo_operateur`    varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `k_date`   (`date`),
  KEY `k_etab`   (`id_etablissement`,`date`),
  KEY `k_acteur` (`acteur_type`,`acteur_login`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Cache de géolocalisation IP (ip-api.com) ────────────────────────
CREATE TABLE IF NOT EXISTS `geo_ip_cache` (
  `ip`         varchar(45)  NOT NULL,
  `pays`       varchar(60)  DEFAULT NULL,
  `region`     varchar(80)  DEFAULT NULL,
  `ville`      varchar(80)  DEFAULT NULL,
  `operateur`  varchar(120) DEFAULT NULL,
  `ok`         tinyint(1)   NOT NULL DEFAULT 0,
  `maj_le`     datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Module « Privilèges » : règles d'accès par école (association/acces.php) ─
--  portee : 'role' (nom de fonction) | 'user' (login du compte école)
--  niveau : 'masque' | 'lecture' | 'ecriture'  (aucune ligne = défaut du rôle)
--  cle    : 'grp:<Nom de groupe de menu>' | url d'entrée de menu
CREATE TABLE IF NOT EXISTS `acces_regle` (
  `id`               bigint      NOT NULL AUTO_INCREMENT,
  `id_etablissement` int         NOT NULL,
  `portee`           enum('role','user') NOT NULL,
  `cible`            varchar(60)  NOT NULL,
  `cle`              varchar(120) NOT NULL,
  `niveau`           enum('masque','lecture','ecriture') NOT NULL,
  `maj_le`           datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_regle` (`id_etablissement`,`portee`,`cible`,`cle`),
  KEY `k_etab` (`id_etablissement`)
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
