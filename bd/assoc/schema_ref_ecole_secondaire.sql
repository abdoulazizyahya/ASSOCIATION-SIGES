-- =====================================================================
--  bd/assoc/schema_ref_ecole_secondaire.sql
--  Schéma de référence chargé par charger_schema_ecole() pour toute
--  nouvelle école avec etablissement.type_enseignement = 'secondaire'.
--  Distinct de schema_ref_ecole.sql (primaire) — voir plan
--  « Intégration du secondaire (LAM_ABZ) dans SIGES » : les deux moteurs
--  de notes (compétence/trimestre vs séquence primaire) sont incompatibles,
--  pas de fusion. Origine : dump structure-only de la base LAM_ABZ réelle
--  (projet C:\wamp64\www\LAM_ABZ), noms de tables/colonnes conservés tels
--  quels (`utilisateur`, `note`, `matiere`, `classe.id`...) pour ne pas
--  retoucher les pages portées dans secondaire/. Complété avec les 4
--  tables `licence*` (identiques à bd/migration_v56.sql + v57, module
--  bd/lib/licence.php agnostique du schéma pédagogique).
-- =====================================================================

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `absence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `absence` (
  `mat_elv` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_trim` int NOT NULL,
  `IDClasses` int NOT NULL,
  `nbre_heure_non_jus` int NOT NULL,
  `nbre_heure_jus` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `mat_elv_2` (`mat_elv`,`id_trim`,`IDClasses`,`val_annee`),
  KEY `mat_elv` (`mat_elv`,`id_trim`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  KEY `IDClasses` (`IDClasses`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `absence_justifiee`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `absence_justifiee` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_eleve` int NOT NULL,
  `id_matiere` int NOT NULL,
  `id_seq` int NOT NULL,
  `id_competence` int unsigned DEFAULT NULL,
  `justifie` tinyint(1) NOT NULL DEFAULT '0',
  `raison` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_abs` (`id_eleve`,`id_matiere`,`id_seq`),
  KEY `id_matiere` (`id_matiere`),
  KEY `id_seq` (`id_seq`),
  KEY `fk_absjust_competence` (`id_competence`),
  CONSTRAINT `fk_absjust_competence` FOREIGN KEY (`id_competence`) REFERENCES `competence` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `affectation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `affectation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_matiere` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_aff` (`id_matiere`,`id_classe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `annee_scolaire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `annee_scolaire` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(12) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `apee_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `apee_config` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `nom_president` varchar(150) DEFAULT NULL,
  `nom_tresorier` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_apee_annee` (`id_annee`),
  CONSTRAINT `fk_apee_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `arrondissement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `arrondissement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_departement` int unsigned NOT NULL,
  `nom` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_departement` (`id_departement`),
  CONSTRAINT `fk_arrondissement_departement` FOREIGN KEY (`id_departement`) REFERENCES `departement` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=100606 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `classe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classe` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `designation` varchar(80) NOT NULL,
  `effectif_max` smallint NOT NULL DEFAULT '70',
  `ordre` tinyint NOT NULL DEFAULT '1',
  `archivee` tinyint(1) NOT NULL DEFAULT '0',
  `code_niveau` varchar(50) DEFAULT NULL,
  `libelle_section` varchar(50) DEFAULT NULL,
  `id_filiere` varchar(50) DEFAULT NULL,
  `id_serie` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `code_niveau` (`code_niveau`),
  KEY `libelle_section` (`libelle_section`),
  KEY `id_filiere` (`id_filiere`),
  KEY `id_serie` (`id_serie`),
  CONSTRAINT `classe_ibfk_1` FOREIGN KEY (`libelle_section`) REFERENCES `section_classe` (`libelle_section`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `classe_ibfk_filiere` FOREIGN KEY (`id_filiere`) REFERENCES `filiere` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `classe_ibfk_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `classe_ibfk_serie` FOREIGN KEY (`id_serie`) REFERENCES `serie` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competence` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_matiere` int unsigned NOT NULL,
  `code_niveau` varchar(25) NOT NULL,
  `id_trim` int unsigned NOT NULL,
  `libelle` varchar(150) NOT NULL,
  `libelle_en` varchar(150) DEFAULT NULL,
  `ordre` int unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `idx_competence_lookup` (`id_matiere`,`code_niveau`,`id_trim`),
  KEY `fk_competence_niveau` (`code_niveau`),
  KEY `fk_competence_trim` (`id_trim`),
  CONSTRAINT `fk_competence_matiere` FOREIGN KEY (`id_matiere`) REFERENCES `matiere` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_competence_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON UPDATE CASCADE,
  CONSTRAINT `fk_competence_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1686 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `critere_conseil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `critere_conseil` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_classe` int unsigned NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `moyenne_admission` decimal(4,2) DEFAULT NULL,
  `moyenne_exclusion` decimal(4,2) DEFAULT NULL,
  `heures_absence_max` smallint unsigned DEFAULT NULL,
  `jours_exclusion_max` smallint unsigned DEFAULT NULL,
  `seuil_tableau_honneur` decimal(4,2) DEFAULT NULL,
  `seuil_encouragement` decimal(4,2) DEFAULT NULL,
  `seuil_felicitation` decimal(4,2) DEFAULT NULL,
  `maj_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_critere_classe_annee` (`id_classe`,`id_annee`),
  KEY `fk_critere_annee` (`id_annee`),
  CONSTRAINT `fk_critere_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_critere_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `decision_conseil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_conseil` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `type` enum('trimestre','annee') NOT NULL,
  `id_trim` int unsigned NOT NULL DEFAULT '0',
  `decision` varchar(40) DEFAULT NULL,
  `next_classe` int unsigned DEFAULT NULL,
  `observation` varchar(255) DEFAULT NULL,
  `date_decision` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_decision` (`id_eleve`,`id_annee`,`type`,`id_trim`),
  KEY `idx_dc_classe` (`id_classe`,`id_annee`),
  KEY `fk_dc_annee` (`id_annee`),
  KEY `fk_dc_next` (`next_classe`),
  CONSTRAINT `fk_dc_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dc_next` FOREIGN KEY (`next_classe`) REFERENCES `classe` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `demande_document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demande_document` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_document` enum('attestation','prise','reprise') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` enum('en_attente_censeur','en_attente_proviseur','rejetee_censeur','rejetee_proviseur','validee') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'en_attente_censeur',
  `date_demande` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_censeur` int unsigned DEFAULT NULL,
  `date_censeur` datetime DEFAULT NULL,
  `commentaire_censeur` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_proviseur` int unsigned DEFAULT NULL,
  `date_proviseur` datetime DEFAULT NULL,
  `commentaire_proviseur` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dd_matricule` (`matricule_ens`),
  KEY `idx_dd_statut` (`statut`),
  KEY `fk_dd_censeur` (`id_censeur`),
  KEY `fk_dd_proviseur` (`id_proviseur`),
  CONSTRAINT `fk_dd_censeur` FOREIGN KEY (`id_censeur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dd_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE,
  CONSTRAINT `fk_dd_proviseur` FOREIGN KEY (`id_proviseur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_region` int unsigned NOT NULL,
  `nom` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_region` (`id_region`),
  CONSTRAINT `fk_departement_region` FOREIGN KEY (`id_region`) REFERENCES `region` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1007 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `discipline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `discipline` (
  `id_mat` int NOT NULL,
  `IDClasses` int NOT NULL,
  `id_groupe` int NOT NULL,
  `coef` int NOT NULL DEFAULT '1',
  `ordre` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '1',
  PRIMARY KEY (`id_mat`,`IDClasses`,`id_groupe`),
  KEY `discipline_groupe` (`id_groupe`),
  KEY `discipline_classe` (`IDClasses`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dispenser`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispenser` (
  `matricule_ens` int NOT NULL,
  `IDClasses` int NOT NULL,
  `id_mat` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_disp` (`IDClasses`,`id_mat`,`val_annee`),
  KEY `matricule_ens` (`matricule_ens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eleve`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eleve` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `matricule` varchar(30) NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `sexe` enum('M','F') NOT NULL DEFAULT 'M',
  `date_naiss` date DEFAULT NULL,
  `lieu_naiss` varchar(100) DEFAULT NULL,
  `region_naiss` varchar(100) DEFAULT NULL,
  `departement_naiss` varchar(100) DEFAULT NULL,
  `arrondissement_naiss` varchar(150) DEFAULT NULL,
  `id_pere` int unsigned DEFAULT NULL,
  `id_mere` int unsigned DEFAULT NULL,
  `adresse` varchar(200) DEFAULT NULL,
  `telephone` varchar(50) DEFAULT NULL,
  `niu` varchar(30) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `photo_bin` mediumblob,
  `statut` enum('actif','desactive') NOT NULL DEFAULT 'actif',
  `cree_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `matricule` (`matricule`),
  KEY `nom` (`nom`,`prenom`),
  KEY `id_pere` (`id_pere`),
  KEY `id_mere` (`id_mere`),
  CONSTRAINT `fk_eleve_mere` FOREIGN KEY (`id_mere`) REFERENCES `parent` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eleve_pere` FOREIGN KEY (`id_pere`) REFERENCES `parent` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=670 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enseignant` (
  `matricule_ens` int NOT NULL AUTO_INCREMENT,
  `nom_ens` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom_ens` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `civilite_ens` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tel_ens` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_fonction` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexe_ens` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_ens` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_naiss` date DEFAULT NULL,
  `lieu_naiss` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_origine` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departement_origine` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrondissement_origine` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tribu` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ethnie` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `situation_matrimoniale` enum('Celibataire','Marie','Divorce','Veuf') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poste_anterieur` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lieu_anterieur` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `num_acte_recrutement` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_acte_recrutement` date DEFAULT NULL,
  `date_entree_fp` date DEFAULT NULL,
  `type_affectation` enum('Arrete','Note de service','Decision') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `num_note_affectation` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_note_affectation` date DEFAULT NULL,
  `date_prise_service` date DEFAULT NULL,
  `qualite` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `diplome` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specialite` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_1ere_admin` date DEFAULT NULL,
  `date_1ere_etab` date DEFAULT NULL,
  `matiere_enseignee` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signature` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matricule_cnps` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indice_grille` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nb_enfants` tinyint unsigned DEFAULT '0',
  `nb_pers_charge` tinyint unsigned DEFAULT '0',
  `date_recrutement` date DEFAULT NULL,
  `mode_paiement` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_banque` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compte_bancaire` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`matricule_ens`),
  KEY `id_grade` (`id_grade`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enseignat_principal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enseignat_principal` (
  `matricule_ens` int NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_pp` (`IDClasses`,`val_annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `etablissement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `etablissement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom_fr` varchar(250) NOT NULL DEFAULT '',
  `nom_en` varchar(250) DEFAULT NULL,
  `sigle` varchar(20) DEFAULT NULL,
  `immatriculation` varchar(50) DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `boite_postale` varchar(50) DEFAULT NULL,
  `ville` varchar(100) DEFAULT NULL,
  `telephone` varchar(100) DEFAULT '',
  `email` varchar(150) DEFAULT '',
  `region_fr` varchar(150) DEFAULT NULL,
  `region_en` varchar(150) DEFAULT NULL,
  `departement_fr` varchar(150) DEFAULT NULL,
  `division_en` varchar(150) DEFAULT NULL,
  `arrondissement_fr` varchar(150) DEFAULT NULL,
  `subdivision_en` varchar(150) DEFAULT NULL,
  `chef_etablissement` varchar(150) DEFAULT 'Le Proviseur',
  `chef_etablissement_en` varchar(150) DEFAULT 'The Principal',
  `statut` enum('public','prive') NOT NULL DEFAULT 'public',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exclusion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exclusion` (
  `mat_elv` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_trim` int NOT NULL,
  `classe` int NOT NULL,
  `nbre_jours` int DEFAULT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  KEY `val_annee` (`val_annee`),
  KEY `id_trim` (`id_trim`),
  KEY `mat_elv` (`mat_elv`),
  KEY `classe` (`classe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `filiere`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `filiere` (
  `id` varchar(30) NOT NULL,
  `libelle` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `format_carte`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `format_carte` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(60) NOT NULL,
  `largeur_mm` decimal(6,1) NOT NULL,
  `hauteur_mm` decimal(6,1) NOT NULL,
  `cartes_par_page` tinyint unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groupe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groupe` (
  `id_groupe_comp` int NOT NULL AUTO_INCREMENT,
  `libelle_groupe_comp` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_section` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_groupe_comp`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inscription`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inscription` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_serie` int unsigned DEFAULT NULL,
  `id_annee` int unsigned NOT NULL,
  `statut` varchar(30) DEFAULT 'Nouveau',
  `date_inscription` date DEFAULT (curdate()),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_insc` (`id_eleve`,`id_annee`),
  KEY `id_classe` (`id_classe`),
  KEY `id_annee` (`id_annee`),
  KEY `idx_insc_serie` (`id_serie`),
  CONSTRAINT `inscription_ibfk_1` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscription_ibfk_2` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inscription_ibfk_3` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insc_serie` FOREIGN KEY (`id_serie`) REFERENCES `serie` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=670 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `matiere`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matiere` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) DEFAULT NULL,
  `libelle` varchar(120) NOT NULL,
  `libelle_en` varchar(120) DEFAULT NULL,
  `libelle_section` varchar(50) DEFAULT NULL,
  `ordre` tinyint DEFAULT '1',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `fk_matiere_section` (`libelle_section`),
  CONSTRAINT `fk_matiere_section` FOREIGN KEY (`libelle_section`) REFERENCES `section_classe` (`libelle_section`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `niveau`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `niveau` (
  `code_niveau` varchar(25) NOT NULL,
  `libelle_niv` varchar(50) DEFAULT NULL,
  `id_cycle` varchar(20) NOT NULL,
  `ordre_niveau` varchar(5) NOT NULL,
  PRIMARY KEY (`code_niveau`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `note`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `note` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_matiere` int unsigned NOT NULL,
  `id_seq` int unsigned DEFAULT NULL,
  `id_competence` int unsigned DEFAULT NULL,
  `valeur` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note` (`id_eleve`,`id_matiere`,`id_seq`),
  KEY `id_matiere` (`id_matiere`),
  KEY `id_seq` (`id_seq`),
  KEY `fk_note_competence` (`id_competence`),
  CONSTRAINT `fk_note_competence` FOREIGN KEY (`id_competence`) REFERENCES `competence` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `note_ibfk_1` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_ibfk_2` FOREIGN KEY (`id_matiere`) REFERENCES `matiere` (`id`) ON DELETE CASCADE,
  CONSTRAINT `note_ibfk_3` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=65537 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_utilisateur` int unsigned NOT NULL,
  `message` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `lien` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lue` tinyint(1) NOT NULL DEFAULT '0',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`id_utilisateur`),
  KEY `idx_notif_lue` (`lue`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obligation_frais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `obligation_frais` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) NOT NULL,
  `code_fixe` varchar(20) DEFAULT NULL,
  `portee` enum('etablissement','cycle','niveau') NOT NULL DEFAULT 'niveau',
  `code_niveau` varchar(25) DEFAULT NULL,
  `id_cycle` varchar(20) DEFAULT NULL,
  `montant` decimal(10,2) NOT NULL,
  `mode_paiement` enum('cash','operateur') NOT NULL DEFAULT 'cash',
  `id_annee` int unsigned NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_obligation` (`libelle`,`portee`,`code_niveau`,`id_cycle`,`id_annee`),
  KEY `idx_obligation_niveau` (`code_niveau`),
  KEY `idx_obligation_annee` (`id_annee`),
  CONSTRAINT `fk_obligation_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_obligation_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operateur_paiement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `operateur_paiement` (
  `id` varchar(20) NOT NULL,
  `libelle` varchar(60) NOT NULL,
  `logo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paiement_frais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paiement_frais` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `id_obligation` int unsigned NOT NULL,
  `id_operateur` varchar(20) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `ref_paiement` varchar(50) DEFAULT NULL,
  `date_paiement` date NOT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `numero_recu` varchar(20) NOT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pf_eleve` (`id_eleve`,`id_annee`,`id_obligation`),
  KEY `idx_pf_classe` (`id_classe`,`id_annee`),
  KEY `fk_pf_annee` (`id_annee`),
  KEY `fk_pf_obligation` (`id_obligation`),
  KEY `fk_pf_operateur` (`id_operateur`),
  KEY `fk_pf_utilisateur` (`id_utilisateur`),
  KEY `idx_numero_recu` (`numero_recu`),
  CONSTRAINT `fk_pf_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_obligation` FOREIGN KEY (`id_obligation`) REFERENCES `obligation_frais` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_operateur` FOREIGN KEY (`id_operateur`) REFERENCES `operateur_paiement` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pf_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ── PAIEMENT PRIVÉ ──────────────────────────────────────────────────
-- Comptabilité 100% indépendante de PAIEMENT PUBLIQUE ci-dessus
-- (obligation_frais/paiement_frais/operateur_paiement, porté de LAM_ABZ) —
-- nouveau module porté du module Finances/Dépenses du PRIMAIRE
-- (pages/finances/*, pages/depenses/*, bd/assoc/schema_ref_ecole.sql :
-- obligation/paiement_frais/depense/categorie_depense), adapté au schéma
-- secondaire (id_annee/id_classe/id_eleve entiers, pas val_annee/IDClasses/
-- id_eleve texte). Demande explicite du 17/09/2026 : « copié exactement,
-- adapté au secondaire », tables séparées. Pas de concept « Cas social »
-- (réduction de frais) côté secondaire pour l'instant — absent de ce schéma,
-- hors périmètre de cette étape.
DROP TABLE IF EXISTS `categorie_depense_privee`;
CREATE TABLE `categorie_depense_privee` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `libelle` (`libelle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `depense_privee`;
CREATE TABLE `depense_privee` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_categorie` int unsigned NOT NULL,
  `libelle` varchar(200) NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_depense` date NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `beneficiaire` varchar(150) DEFAULT NULL,
  `observation` varchar(255) DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_categorie` (`id_categorie`),
  KEY `id_annee` (`id_annee`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_depriv_categorie` FOREIGN KEY (`id_categorie`) REFERENCES `categorie_depense_privee` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_depriv_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_depriv_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `obligation_privee`;
CREATE TABLE `obligation_privee` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom_obligation` varchar(200) NOT NULL,
  `montant_obligation` decimal(10,2) NOT NULL,
  `code_niveau` varchar(25) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `code_niveau` (`code_niveau`),
  CONSTRAINT `fk_oblpriv_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `paiement_prive`;
CREATE TABLE `paiement_prive` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_versement` int unsigned DEFAULT NULL,
  `id_eleve` int unsigned NOT NULL,
  `id_classe` int unsigned NOT NULL,
  `id_annee` int unsigned NOT NULL,
  `id_obligation` int unsigned NOT NULL,
  `montant_paiement` decimal(10,2) NOT NULL,
  `date_paiement` date NOT NULL,
  `ref_paiement` varchar(50) DEFAULT NULL,
  `mode_paiement` enum('ESPECES','ORANGE_MONEY','MOMO','BANQUE','AUTRE') NOT NULL DEFAULT 'ESPECES',
  `id_utilisateur` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_eleve` (`id_eleve`),
  KEY `id_classe` (`id_classe`),
  KEY `id_annee` (`id_annee`),
  KEY `id_obligation` (`id_obligation`),
  KEY `id_versement` (`id_versement`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_paypriv_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paypriv_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paypriv_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paypriv_obligation` FOREIGN KEY (`id_obligation`) REFERENCES `obligation_privee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paypriv_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ── Paie (Ressources humaines) ───────────────────────────────────────
-- Porté du module Paie du PRIMAIRE (pages/paie/*, paie_fonctions.php,
-- bd/migration_v34.sql/v35.sql) — mêmes noms de tables/colonnes que le
-- primaire, VOLONTAIREMENT identiques : paie_fonctions.php est réutilisé
-- TEL QUEL par secondaire/pages/paie/* (aucune duplication du moteur de
-- calcul), chaque école ayant de toute façon sa propre base. Seule
-- différence de fond : bulletin_paie.id_depense référence `depense_privee`
-- (PAIEMENT PRIVÉ ci-dessus), pas `depense` (absente du schéma secondaire)
-- — marquer_bulletin_paye() (paie_fonctions.php) est rendue type-aware pour
-- ça. Demande explicite du 21/09/2026.
-- grade_enseignant/indemnite_grade en utf8mb4_unicode_ci (pas le
-- utf8mb4_0900_ai_ci par défaut du reste de ce fichier) : enseignant.id_grade
-- (table héritée, tout en unicode_ci) référence grade_enseignant.code_grade
-- en clé étrangère (ALTER TABLE plus bas) — MySQL refuse une FK entre deux
-- collations différentes ("are incompatible"), ce qui cassait la création de
-- toute école secondaire neuve (bug réel constaté le 22/09/2026, en testant
-- creer_etablissement() pour la création automatique des comptes par défaut).
DROP TABLE IF EXISTS `grade_enseignant`;
CREATE TABLE `grade_enseignant` (
  `code_grade` varchar(20) NOT NULL,
  `libelle_grade` varchar(150) NOT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`code_grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `indemnite_grade`;
CREATE TABLE `indemnite_grade` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_grade` varchar(20) NOT NULL,
  `libelle_indemnite` varchar(150) NOT NULL,
  `montant` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `code_grade` (`code_grade`),
  CONSTRAINT `fk_indemnite_grade` FOREIGN KEY (`code_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `contrat_enseignant`;
CREATE TABLE `contrat_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_contrat` varchar(30) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `remarques` varchar(255) DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  CONSTRAINT `fk_contrat_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `conge_enseignant`;
CREATE TABLE `conge_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_conge` varchar(30) NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `nb_jours` int NOT NULL DEFAULT '0',
  `motif` varchar(255) DEFAULT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'Validé',
  `deduit_paie` tinyint(1) NOT NULL DEFAULT '0',
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_conge_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_conge_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `avance_salaire`;
CREATE TABLE `avance_salaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_avance` date NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_avance_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_avance_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `periode_paie`;
CREATE TABLE `periode_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mois` tinyint NOT NULL,
  `annee` smallint NOT NULL,
  `libelle` varchar(50) NOT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'Brouillon',
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periode_mois_annee` (`mois`,`annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `bulletin_paie`;
CREATE TABLE `bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_periode` int NOT NULL,
  `matricule_ens` int NOT NULL,
  `code_grade` varchar(20) DEFAULT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_indemnites` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_primes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_retenues` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_avance_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_absence_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `jours_absence` int NOT NULL DEFAULT '0',
  `brut` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_a_payer` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` varchar(20) NOT NULL DEFAULT 'Généré',
  `mode_paiement` varchar(30) DEFAULT NULL,
  `reference_paiement` varchar(50) DEFAULT NULL,
  `date_paiement` date DEFAULT NULL,
  `id_depense` int unsigned DEFAULT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bulletin_periode_ens` (`id_periode`,`matricule_ens`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `id_depense` (`id_depense`),
  CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_depense` FOREIGN KEY (`id_depense`) REFERENCES `depense_privee` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `ligne_bulletin_paie`;
CREATE TABLE `ligne_bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_bulletin` int NOT NULL,
  `type_ligne` varchar(10) NOT NULL,
  `code_rubrique` varchar(10) DEFAULT NULL,
  `libelle` varchar(150) NOT NULL,
  `nb` decimal(10,2) DEFAULT NULL,
  `montant` decimal(12,2) NOT NULL,
  `base` decimal(12,2) DEFAULT NULL,
  `taux_pct` decimal(6,2) DEFAULT NULL,
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_ligne_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DROP TABLE IF EXISTS `remboursement_avance`;
CREATE TABLE `remboursement_avance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_avance` int NOT NULL,
  `id_bulletin` int NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_remboursement` date NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_avance` (`id_avance`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_rembours_avance` FOREIGN KEY (`id_avance`) REFERENCES `avance_salaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rembours_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- FK vers grade_enseignant posée ICI (après sa création ci-dessus) — la
-- table `enseignant` est définie plus haut dans ce fichier, avant
-- `grade_enseignant` : une contrainte inline y aurait référencé une table
-- pas encore créée (échec au chargement séquentiel du dump).
ALTER TABLE `enseignant` ADD CONSTRAINT `fk_enseignant_grade` FOREIGN KEY (`id_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE SET NULL ON UPDATE CASCADE;

DROP TABLE IF EXISTS `parent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parent` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `profession` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telephone2` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `telephone` (`telephone`),
  KEY `nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pdf_couleur`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pdf_couleur` (
  `cle` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `r` tinyint unsigned NOT NULL,
  `g` tinyint unsigned NOT NULL,
  `b` tinyint unsigned NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `question_secrete`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_secrete` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `region`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `region` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_en` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reglage_mention_bulletin`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reglage_mention_bulletin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `moy_tableau_honneur` decimal(4,2) NOT NULL DEFAULT '12.00',
  `heures_max_tableau_honneur` smallint unsigned NOT NULL DEFAULT '8',
  `moy_encouragement` decimal(4,2) NOT NULL DEFAULT '14.00',
  `moy_felicitation` decimal(4,2) NOT NULL DEFAULT '15.00',
  `moy_avert_travail_min` decimal(4,2) NOT NULL DEFAULT '5.00',
  `moy_avert_travail_max` decimal(4,2) NOT NULL DEFAULT '7.30',
  `moy_blame_travail_max` decimal(4,2) NOT NULL DEFAULT '5.00',
  `heures_avert_conduite_min` smallint unsigned NOT NULL DEFAULT '5',
  `heures_avert_conduite_max` smallint unsigned NOT NULL DEFAULT '10',
  `heures_blame_conduite_min` smallint unsigned NOT NULL DEFAULT '10',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reglage_annee` (`id_annee`),
  CONSTRAINT `fk_reglage_mention_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reglage_paiement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reglage_paiement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_annee` int unsigned NOT NULL,
  `montant_frais_operateur` decimal(10,2) NOT NULL DEFAULT '200.00',
  `couleur_fond_1` varchar(7) NOT NULL DEFAULT '#FFF6C8',
  `couleur_fond_2` varchar(7) NOT NULL DEFAULT '#FFCDD2',
  `couleur_fond_3` varchar(7) NOT NULL DEFAULT '#CDE8CD',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reglage_paiement_annee` (`id_annee`),
  CONSTRAINT `fk_reglage_paiement_annee` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `retard`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `retard` (
  `mat_elv` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_trim` int NOT NULL,
  `IDClasses` int NOT NULL,
  `nbre_retards` int NOT NULL DEFAULT '0',
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `mat_elv_2` (`mat_elv`,`id_trim`,`IDClasses`,`val_annee`),
  KEY `mat_elv` (`mat_elv`,`id_trim`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  KEY `IDClasses` (`IDClasses`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `section_classe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `section_classe` (
  `libelle_section` varchar(50) NOT NULL,
  PRIMARY KEY (`libelle_section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequence` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(50) NOT NULL,
  `ordre` tinyint DEFAULT '1',
  `active` tinyint(1) DEFAULT '0',
  `date_debut` date DEFAULT NULL,
  `date_fin` date DEFAULT NULL,
  `id_trim` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_trim` (`id_trim`),
  CONSTRAINT `sequence_ibfk_1` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `serie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `serie` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(80) NOT NULL,
  `id_filiere` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sg`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sg` (
  `matricule_ens` int NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  KEY `matricule_ens` (`matricule_ens`,`IDClasses`,`val_annee`),
  KEY `IDClasses` (`IDClasses`),
  KEY `val_annee` (`val_annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_position` (
  `type_document` varchar(40) NOT NULL,
  `code_signature` varchar(30) NOT NULL,
  `x_pct` decimal(6,3) NOT NULL,
  `y_pct` decimal(6,3) NOT NULL,
  `w_pct` decimal(6,3) NOT NULL,
  `h_pct` decimal(6,3) DEFAULT NULL,
  PRIMARY KEY (`type_document`,`code_signature`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_titulaire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_titulaire` (
  `code` varchar(30) NOT NULL,
  `libelle` varchar(100) NOT NULL,
  `fichier` varchar(255) DEFAULT NULL,
  `role_gestion` varchar(30) NOT NULL,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trimestre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trimestre` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(50) NOT NULL,
  `ordre` tinyint DEFAULT '1',
  `id_annee` int unsigned NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `id_annee` (`id_annee`),
  CONSTRAINT `trimestre_ibfk_1` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trimestre_annulation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trimestre_annulation` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_trim` int unsigned NOT NULL,
  `motif` varchar(255) DEFAULT NULL,
  `id_utilisateur` int unsigned DEFAULT NULL COMMENT 'Qui a annulé (traçabilité)',
  `date_creation` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Quand a été annulé',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trimannul_eleve_trim` (`id_eleve`,`id_trim`),
  KEY `idx_trimannul_trim` (`id_trim`),
  CONSTRAINT `fk_trimannul_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trimannul_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_trimannul_util` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tuteur`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tuteur` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `lien` varchar(50) DEFAULT NULL,
  `telephone` varchar(50) DEFAULT NULL,
  `profession` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_eleve` (`id_eleve`),
  CONSTRAINT `tuteur_ibfk_1` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `utilisateur`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilisateur` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(100) NOT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `login` varchar(80) NOT NULL,
  `mot_de_passe` varchar(255) NOT NULL,
  `role` enum('ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT','INTENDANT','FONDATEUR') NOT NULL DEFAULT 'SECRETAIRE',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `matricule_ens` int DEFAULT NULL,
  `cree_le` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `email` varchar(150) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `fk_util_ens` (`matricule_ens`),
  CONSTRAINT `utilisateur_ibfk_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=148 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `utilisateur_question_secrete`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `utilisateur_question_secrete` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_utilisateur` int unsigned NOT NULL,
  `id_question` int unsigned NOT NULL,
  `reponse_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `maj_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_question` (`id_utilisateur`,`id_question`),
  KEY `idx_uqs_question` (`id_question`),
  CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`) REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_uqs_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `utilisateur` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

DROP TABLE IF EXISTS `licence`;
CREATE TABLE `licence` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_licence` varchar(255) DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_expiration` date NOT NULL,
  `statut` enum('active','suspendue','expiree') NOT NULL DEFAULT 'active',
  `derniere_modification_par` varchar(190) DEFAULT NULL,
  `date_derniere_modification` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `signature` varchar(64) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `licence_historique`;
CREATE TABLE `licence_historique` (
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

DROP TABLE IF EXISTS `licence_securite`;
CREATE TABLE `licence_securite` (
  `id` tinyint NOT NULL,
  `tentatives_echouees` int NOT NULL DEFAULT 0,
  `bloque_le` datetime DEFAULT NULL,
  `dernier_maintenant_vu` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `licence_securite` (`id`, `tentatives_echouees`, `bloque_le`, `dernier_maintenant_vu`) VALUES (1, 0, NULL, NULL);

DROP TABLE IF EXISTS `licence_cles_utilisees`;
CREATE TABLE `licence_cles_utilisees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cle_hash` char(64) NOT NULL,
  `utilisee_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_licence_cles_hash` (`cle_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
