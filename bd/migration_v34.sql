-- =====================================================================
--  jaynitaare_v2 — Migration v34 : Gestion des enseignants (RH complète) + Paie
-- =====================================================================
--  Demande explicite du 15/08/2026 : « au niveau du menu enseignant, c'est
--  juste la liste, bien vouloir mettre en place tout le système de gestion
--  des enseignants jusqu'à la paie ». Choix confirmés par l'utilisateur
--  (AskUserQuestion) : grille salariale par grade/échelon (pas un salaire
--  fixe par enseignant), bulletins AVEC retenues (avances/absences),
--  consultation self-service par l'enseignant lui-même (lecture seule),
--  périmètre complet Fiche + Contrats + Congés + Paie.
--
--  `pages/enseignants/{index,form,apercu,mon_profil,pdf_*}.php` (et tout
--  `pages/paiements/*` et `pages/demandes/*`) sont du code mort d'une
--  première tentative jamais reliée au menu (rôles ADMIN/PROVISEUR/CENSEUR
--  inexistants ici, colonnes/fonctions non définies) — voir prompt de
--  continuité. Cette migration ne touche à rien de tout ça, elle construit
--  le VRAI module sur le VRAI schéma (`enseignant.matricule_ens`/`nom_ens`/
--  `id_fonction`/`id_grade`...).
--
--  Modèle paie = grille salariale (comme le module Dépenses v33, jamais de
--  solde stocké qui pourrait diverger — tout est recalculé à la volée à
--  partir de tables-registres) :
--   - `grade_enseignant`   : catalogue des grades avec salaire de base.
--   - `indemnite_grade`    : indemnités standards (logement, transport…)
--                            par grade, montants configurables.
--   - `contrat_enseignant` : historique des contrats (type, dates).
--   - `conge_enseignant`   : congés/absences enseignant — `deduit_paie=1`
--                            sur un congé "Sans solde"/"Absence non
--                            justifiée" impacte automatiquement le calcul
--                            du bulletin de la période concernée.
--   - `avance_salaire`     : octroi d'avances sur salaire (registre, jamais
--                            de solde stocké) + `remboursement_avance`
--                            (déduction d'une avance sur un bulletin précis
--                            — le solde restant = montant - Σ remboursements).
--   - `periode_paie`       : un "run" de paie mensuel (mois/année).
--   - `bulletin_paie`      : un bulletin par enseignant par période, valeurs
--                            au moment de la génération (snapshot — un
--                            changement de grille salariale plus tard ne
--                            modifie jamais un bulletin déjà généré).
--   - `ligne_bulletin_paie`: détail des gains/retenues affichés sur le PDF.
--
--  Quand un bulletin passe au statut "Payé", une ligne est ajoutée dans
--  `depense` (catégorie "Salaires", déjà seedée par la migration v33) —
--  la paie doit apparaître dans le solde de caisse comme toute autre
--  dépense, cohérence avec solde_caisse() (fonctions.php).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `grade_enseignant` (
  `code_grade`      varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_grade`   varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `salaire_base`    decimal(12,2) NOT NULL DEFAULT 0,
  `ordre_affichage` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`code_grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `indemnite_grade` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `code_grade`        varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_indemnite` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant`           decimal(12,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `code_grade` (`code_grade`),
  CONSTRAINT `fk_indemnite_grade` FOREIGN KEY (`code_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `contrat_enseignant` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_contrat`  varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut`    date NOT NULL,
  `date_fin`      date DEFAULT NULL,
  `actif`         tinyint(1) NOT NULL DEFAULT 1,
  `remarques`     varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le`       timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  CONSTRAINT `fk_contrat_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conge_enseignant` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `matricule_ens`  int NOT NULL,
  `type_conge`     varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut`     date NOT NULL,
  `date_fin`       date NOT NULL,
  `nb_jours`       int NOT NULL DEFAULT 0,
  `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut`         varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Validé',
  `deduit_paie`    tinyint(1) NOT NULL DEFAULT 0,
  `id_utilisateur` int DEFAULT NULL,
  `cree_le`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_conge_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_conge_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `avance_salaire` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `matricule_ens`  int NOT NULL,
  `montant`        decimal(12,2) NOT NULL,
  `date_avance`    date NOT NULL,
  `motif`          varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `cree_le`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_avance_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_avance_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `periode_paie` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `mois`            tinyint NOT NULL,
  `annee`           smallint NOT NULL,
  `libelle`         varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut`          varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Brouillon',
  `cree_le`         timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periode_mois_annee` (`mois`,`annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bulletin_paie` (
  `id`                       int NOT NULL AUTO_INCREMENT,
  `id_periode`               int NOT NULL,
  `matricule_ens`            int NOT NULL,
  `code_grade`               varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salaire_base`             decimal(12,2) NOT NULL DEFAULT 0,
  `total_indemnites`         decimal(12,2) NOT NULL DEFAULT 0,
  `total_primes`             decimal(12,2) NOT NULL DEFAULT 0,
  `total_retenues`           decimal(12,2) NOT NULL DEFAULT 0,
  `montant_avance_deduite`   decimal(12,2) NOT NULL DEFAULT 0,
  `montant_absence_deduite`  decimal(12,2) NOT NULL DEFAULT 0,
  `jours_absence`            int NOT NULL DEFAULT 0,
  `brut`                     decimal(12,2) NOT NULL DEFAULT 0,
  `net_a_payer`              decimal(12,2) NOT NULL DEFAULT 0,
  `statut`                   varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Généré',
  `mode_paiement`            varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_paiement`       varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_paiement`            date DEFAULT NULL,
  `id_depense`               int DEFAULT NULL,
  `id_utilisateur`           int DEFAULT NULL,
  `cree_le`                  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bulletin_periode_ens` (`id_periode`,`matricule_ens`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `id_depense` (`id_depense`),
  CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_depense` FOREIGN KEY (`id_depense`) REFERENCES `depense` (`id_depense`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ligne_bulletin_paie` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `id_bulletin`     int NOT NULL,
  `type_ligne`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle`         varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant`         decimal(12,2) NOT NULL,
  `ordre_affichage` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_ligne_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remboursement_avance` (
  `id`                 int NOT NULL AUTO_INCREMENT,
  `id_avance`          int NOT NULL,
  `id_bulletin`        int NOT NULL,
  `montant`            decimal(12,2) NOT NULL,
  `date_remboursement` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_avance` (`id_avance`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_rembours_avance` FOREIGN KEY (`id_avance`) REFERENCES `avance_salaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rembours_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Colonnes RH manquantes sur `enseignant` (fiche jusque-là purement
-- "état civil", rien pour la gestion du personnel elle-même).
ALTER TABLE `enseignant` ADD COLUMN `statut_ens` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif' AFTER `arrondissement_ens`;
ALTER TABLE `enseignant` ADD COLUMN `date_recrutement` date DEFAULT NULL AFTER `statut_ens`;
ALTER TABLE `enseignant` ADD COLUMN `mode_paiement` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `date_recrutement`;
ALTER TABLE `enseignant` ADD COLUMN `compte_bancaire` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mode_paiement`;

-- Repli texte libre pour l'arrondissement d'origine (`arrondissement_ens`,
-- déjà existant, désormais traité comme eleve.id_arrondissement — FK vers
-- `arrondissement.code_arrond`, cascade région/département/arrondissement,
-- voir assets/js/lieu-cascade.js, déjà générique "élève, enseignant,
-- tuteur…") — même paire de colonnes que eleve.arrondissement_elv.
ALTER TABLE `enseignant` ADD COLUMN `lieu_origine_libre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `compte_bancaire`;

-- `enseignant.id_grade` existait déjà (varchar(20), simple KEY sans FK) mais
-- ne pointait vers aucune table — les 7 lignes existantes ont '' (chaîne
-- vide), pas NULL : normalisé avant d'ajouter la contrainte (NULL est
-- autorisé par une FK, '' ne matchera jamais grade_enseignant.code_grade).
UPDATE `enseignant` SET `id_grade` = NULL WHERE `id_grade` = '';
ALTER TABLE `enseignant` ADD CONSTRAINT `fk_enseignant_grade` FOREIGN KEY (`id_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE SET NULL ON UPDATE CASCADE;

-- Grille de départ — montants PLACEHOLDER (à corriger via Ressources
-- humaines > Grille salariale, écran dédié) : jamais vérifiés avec
-- l'établissement, juste un point de départ pour ne pas démarrer à zéro.
INSERT IGNORE INTO `grade_enseignant` (`code_grade`, `libelle_grade`, `salaire_base`, `ordre_affichage`) VALUES
('VAC',   'Vacataire',                 40000,  1),
('INST1', 'Instituteur adjoint',       60000,  2),
('INST2', 'Instituteur',               80000,  3),
('PROF',  'Professeur des écoles',     100000, 4),
('DIR',   'Direction',                 150000, 5);
