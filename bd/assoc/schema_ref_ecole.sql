
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
  `id_eleve` int unsigned NOT NULL,
  `id_trim` int NOT NULL,
  `classe` int NOT NULL,
  `nbre_jour_non_jus` int NOT NULL,
  `nbre_jour_jus` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_absence_eleve_trim_classe_annee` (`id_eleve`,`id_trim`,`classe`,`val_annee`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  KEY `classe` (`classe`),
  KEY `idx_absence_eleve_trim` (`id_eleve`,`id_trim`),
  CONSTRAINT `absence_ibfk_2` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `absence_ibfk_3` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `absence_ibfk_5` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absence_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `absence_justifiee`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `absence_justifiee` (
  `id_eleve` int unsigned NOT NULL,
  `id_comp` int NOT NULL,
  `id_seq` int NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `justifie` tinyint(1) NOT NULL DEFAULT '0',
  `raison` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`id_comp`,`id_seq`),
  KEY `idx_absjust_classe_seq` (`IDClasses`,`id_seq`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `fk_absjust_comp` (`id_comp`),
  KEY `fk_absjust_seq` (`id_seq`),
  KEY `fk_absjust_annee` (`val_annee`),
  CONSTRAINT `fk_absjust_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_comp` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `absence_justifiee_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `absence_justifiee_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `id_mat` int NOT NULL,
  `id_seq` int NOT NULL,
  `classe` int NOT NULL,
  `justifie` tinyint(1) NOT NULL DEFAULT '0',
  `raison` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`id_mat`,`id_seq`),
  KEY `idx_absjustar_classe_seq` (`classe`,`id_seq`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `fk_absjustar_mat` (`id_mat`),
  KEY `fk_absjustar_seq` (`id_seq`),
  CONSTRAINT `fk_absjustar_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_mat` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `annee_scolaire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `annee_scolaire` (
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `Etat_annee_scolaire` tinyint DEFAULT '0',
  PRIMARY KEY (`val_annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `arrondissement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `arrondissement` (
  `code_arrond` int NOT NULL AUTO_INCREMENT,
  `intitule_arrond` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_depart` int NOT NULL,
  PRIMARY KEY (`code_arrond`),
  UNIQUE KEY `uk_arrond_depart` (`code_depart`,`intitule_arrond`),
  KEY `code_depart` (`code_depart`),
  CONSTRAINT `arrondissement_ibfk_1` FOREIGN KEY (`code_depart`) REFERENCES `departement` (`code_depart`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=365 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `avance_salaire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `avance_salaire` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_avance` date NOT NULL,
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_avance_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_avance_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bulletin_paie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_periode` int NOT NULL,
  `matricule_ens` int NOT NULL,
  `code_grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_indemnites` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_primes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_retenues` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_avance_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `montant_absence_deduite` decimal(12,2) NOT NULL DEFAULT '0.00',
  `jours_absence` int NOT NULL DEFAULT '0',
  `brut` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_a_payer` decimal(12,2) NOT NULL DEFAULT '0.00',
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Généré',
  `mode_paiement` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_paiement` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_paiement` date DEFAULT NULL,
  `id_depense` int DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bulletin_periode_ens` (`id_periode`,`matricule_ens`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `id_depense` (`id_depense`),
  CONSTRAINT `fk_bulletin_depense` FOREIGN KEY (`id_depense`) REFERENCES `depense` (`id_depense`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_periode` FOREIGN KEY (`id_periode`) REFERENCES `periode_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bulletin_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `categorie_depense`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorie_depense` (
  `id_categorie` int NOT NULL AUTO_INCREMENT,
  `libelle` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_categorie`),
  UNIQUE KEY `uk_categorie_depense_libelle` (`libelle`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `classe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `classe` (
  `IDClasses` int NOT NULL AUTO_INCREMENT,
  `DesignationClasses` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Niveau` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `classe_suivante` int DEFAULT NULL,
  UNIQUE KEY `IDClasses` (`IDClasses`),
  KEY `WDIDX_Classes_Niveau` (`Niveau`),
  KEY `fk_classe_classe_suivante` (`classe_suivante`),
  CONSTRAINT `classe_ibfk_1` FOREIGN KEY (`Niveau`) REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_classe_classe_suivante` FOREIGN KEY (`classe_suivante`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `competence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `competence` (
  `id_comp` int NOT NULL AUTO_INCREMENT,
  `code_comp` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_comp` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_groupe_comp` int NOT NULL,
  PRIMARY KEY (`id_comp`),
  KEY `id_groupe_comp` (`id_groupe_comp`),
  CONSTRAINT `competence_ibfk_1` FOREIGN KEY (`id_groupe_comp`) REFERENCES `groupe_competence` (`id_groupe_comp`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `composer_sequence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `composer_sequence` (
  `id_eleve` int unsigned NOT NULL,
  `id_comp` int NOT NULL,
  `IDClasses` int NOT NULL,
  `id_seq` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `note_orale` float NOT NULL,
  `note_ecrite` float NOT NULL,
  `note_pratique` float NOT NULL,
  `note_savoir_etre` float NOT NULL,
  `note_total_points` float NOT NULL,
  PRIMARY KEY (`id_eleve`,`id_comp`,`IDClasses`,`id_seq`,`val_annee`),
  KEY `id_comp` (`id_comp`),
  KEY `IDClasses` (`IDClasses`),
  KEY `id_seq` (`id_seq`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `composer_sequence_ibfk_2` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `composer_sequence_ibfk_3` FOREIGN KEY (`IDClasses`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `composer_sequence_ibfk_4` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `composer_sequence_ibfk_5` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_composer_sequence_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `composer_sequence_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `composer_sequence_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `id_seq` int NOT NULL,
  `id_mat` int NOT NULL,
  `classe` int NOT NULL DEFAULT '0',
  `note_orale` float DEFAULT NULL,
  `note_ecrite` float DEFAULT NULL,
  `note_pratique` float DEFAULT NULL,
  `note_total_points` float DEFAULT NULL,
  PRIMARY KEY (`id_eleve`,`id_seq`,`id_mat`,`classe`),
  KEY `id_seq` (`id_seq`),
  KEY `id_mat` (`id_mat`),
  KEY `classe` (`classe`),
  CONSTRAINT `composer_sequence_arabe_ibfk_2` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `composer_sequence_arabe_ibfk_3` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `composer_sequence_arabe_ibfk_4` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_composer_sequence_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conge_enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `conge_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_conge` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `nb_jours` int NOT NULL DEFAULT '0',
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Validé',
  `deduit_paie` tinyint(1) NOT NULL DEFAULT '0',
  `id_utilisateur` int DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_conge_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_conge_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contrat_enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contrat_enseignant` (
  `id` int NOT NULL AUTO_INCREMENT,
  `matricule_ens` int NOT NULL,
  `type_contrat` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `remarques` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `matricule_ens` (`matricule_ens`),
  CONSTRAINT `fk_contrat_enseignant` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `critere_conseil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `critere_conseil` (
  `id_classe` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `moyenne_admission` decimal(4,2) DEFAULT NULL,
  `moyenne_exclusion` decimal(4,2) DEFAULT NULL,
  `jours_absence_max` int DEFAULT NULL,
  `jours_exclusion_max` int DEFAULT NULL,
  `seuil_tableau_honneur` decimal(4,2) DEFAULT NULL,
  `seuil_encouragement` decimal(4,2) DEFAULT NULL,
  `seuil_felicitation` decimal(4,2) DEFAULT NULL,
  PRIMARY KEY (`id_classe`,`val_annee`),
  KEY `fk_critere_conseil_annee` (`val_annee`),
  CONSTRAINT `fk_critere_conseil_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_critere_conseil_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `critere_conseil_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `critere_conseil_arabe` (
  `id_classe` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `moyenne_admission` decimal(4,2) DEFAULT NULL,
  `moyenne_exclusion` decimal(4,2) DEFAULT NULL,
  `jours_absence_max` int DEFAULT NULL,
  `jours_exclusion_max` int DEFAULT NULL,
  `seuil_tableau_honneur` decimal(4,2) DEFAULT NULL,
  `seuil_encouragement` decimal(4,2) DEFAULT NULL,
  `seuil_felicitation` decimal(4,2) DEFAULT NULL,
  PRIMARY KEY (`id_classe`,`val_annee`),
  KEY `fk_critere_conseil_arabe_annee` (`val_annee`),
  CONSTRAINT `fk_critere_conseil_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_critere_conseil_arabe_classe` FOREIGN KEY (`id_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `decision_conseil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_conseil` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `id_trim` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RAS',
  `observation` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`classe`,`id_trim`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_decision_conseil_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `decision_conseil_annuel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_conseil_annuel` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `next_classe` int DEFAULT NULL,
  `observation` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`classe`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  KEY `fk_decision_annuel_next_classe` (`next_classe`),
  CONSTRAINT `fk_decision_annuel_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_next_classe` FOREIGN KEY (`next_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `decision_conseil_annuel_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_conseil_annuel_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `next_classe` int DEFAULT NULL,
  `observation` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`classe`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  KEY `fk_decision_annuel_arabe_next_classe` (`next_classe`),
  CONSTRAINT `fk_decision_annuel_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_annuel_arabe_next_classe` FOREIGN KEY (`next_classe`) REFERENCES `classe` (`IDClasses`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `decision_conseil_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `decision_conseil_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `id_trim` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RAS',
  `observation` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `modifie_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`classe`,`id_trim`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `id_trim` (`id_trim`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_decision_conseil_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_decision_conseil_arabe_trim` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departement` (
  `code_depart` int NOT NULL AUTO_INCREMENT,
  `intitule_depart` varchar(250) COLLATE utf8mb4_unicode_ci NOT NULL,
  `chef_lieu` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_region` int NOT NULL,
  PRIMARY KEY (`code_depart`),
  KEY `code_region` (`code_region`),
  CONSTRAINT `departement_ibfk_1` FOREIGN KEY (`code_region`) REFERENCES `region` (`id_region`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `depense`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `depense` (
  `id_depense` int NOT NULL AUTO_INCREMENT,
  `id_categorie` int NOT NULL,
  `libelle` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` decimal(12,2) NOT NULL,
  `date_depense` date NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `beneficiaire` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `observation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_depense`),
  KEY `id_categorie` (`id_categorie`),
  KEY `val_annee` (`val_annee`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `date_depense` (`date_depense`),
  CONSTRAINT `fk_depense_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_depense_categorie` FOREIGN KEY (`id_categorie`) REFERENCES `categorie_depense` (`id_categorie`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_depense_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `discipline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `discipline` (
  `IDClasses` int NOT NULL,
  `id_comp` int NOT NULL,
  `annee_scol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orale` float DEFAULT NULL,
  `ecrite` float DEFAULT NULL,
  `pratique` float DEFAULT NULL,
  `savoir_etre` float DEFAULT NULL,
  `total_points` float DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  UNIQUE KEY `IDClass_comp_annee` (`IDClasses`,`id_comp`,`annee_scol`),
  KEY `id_comp` (`id_comp`),
  KEY `annee_scol` (`annee_scol`),
  CONSTRAINT `discipline_ibfk_1` FOREIGN KEY (`annee_scol`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `discipline_ibfk_2` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `discipline_ibfk_3` FOREIGN KEY (`IDClasses`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `discipline_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `discipline_arabe` (
  `IDClasses` int NOT NULL,
  `id_mat` int NOT NULL,
  `annee_scol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `orale` float NOT NULL DEFAULT '0',
  `ecrite` float NOT NULL DEFAULT '0',
  `pratique` float NOT NULL DEFAULT '0',
  `total_points` float NOT NULL DEFAULT '0',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`IDClasses`,`id_mat`,`annee_scol`),
  KEY `fk_discar_mat` (`id_mat`),
  CONSTRAINT `fk_discar_classe` FOREIGN KEY (`IDClasses`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_discar_mat` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dispenser`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dispenser` (
  `IDClasses` int NOT NULL,
  `id_comp` int NOT NULL,
  `matricule_ens` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`IDClasses`,`id_comp`,`matricule_ens`,`val_annee`),
  KEY `id_comp` (`id_comp`),
  KEY `matricule_ens` (`matricule_ens`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `dispenser_ibfk_1` FOREIGN KEY (`IDClasses`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dispenser_ibfk_2` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dispenser_ibfk_3` FOREIGN KEY (`matricule_ens`) REFERENCES `enseignant` (`matricule_ens`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `dispenser_ibfk_4` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dossier_eleve`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dossier_eleve` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `type_document` enum('acte_naissance','carnet_vaccination','bulletin','document_transfert','photo_4x4','autre') COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fichier` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date_ajout` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ajoute_par` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dossier_id_eleve` (`id_eleve`),
  CONSTRAINT `fk_dossier_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `eleve`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `eleve` (
  `id_eleve` int unsigned NOT NULL AUTO_INCREMENT,
  `Mat_elv` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Nom_elv` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Nom_arabe_elv` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Prenom_elv` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Date_naiss_elv` varchar(15) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Lieu_naiss_elv` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Sexe_elv` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '0',
  `arrondissement_elv` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_arrondissement` int DEFAULT NULL,
  `Adresse_elv` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `niu` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Photo_elv` longblob,
  `statut` enum('actif','desactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`id_eleve`),
  UNIQUE KEY `Mat_elv` (`Mat_elv`),
  KEY `fk_eleve_arrondissement` (`id_arrondissement`),
  CONSTRAINT `fk_eleve_arrondissement` FOREIGN KEY (`id_arrondissement`) REFERENCES `arrondissement` (`code_arrond`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=245 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Configuration du format de matricule (migration v52) — ligne unique id=1.
DROP TABLE IF EXISTS `matricule_config`;
CREATE TABLE `matricule_config` (
  `id` tinyint(1) NOT NULL DEFAULT '1',
  `mode` enum('auto','manuel') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `format` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '{AA}{NIV}{SEQ}',
  `longueur_seq` tinyint(2) NOT NULL DEFAULT '3',
  `sequence_par` enum('annee_niveau','annee','globale') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'annee_niveau',
  `maj_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `matricule_config` (`id`, `mode`, `format`, `longueur_seq`, `sequence_par`) VALUES
(1, 'auto', '{AA}{NIV}{SEQ}', 3, 'annee_niveau');

-- Privilèges par utilisateur (migration v53) — deny-list de menus/sous-menus.
-- cle = 'grp:<Groupe>' ou '<url de l'entrée>'. Vide par défaut (accès complet
-- selon le rôle). Voir layout/menu.php + pages/utilisateurs/acces.php.
DROP TABLE IF EXISTS `acces_utilisateur`;
CREATE TABLE `acces_utilisateur` (
  `id_user` int(11) NOT NULL,
  `cle` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `refuse_le` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_user`,`cle`),
  KEY `idx_acces_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enseignant` (
  `matricule_ens` int NOT NULL AUTO_INCREMENT,
  `nom_ens` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom_ens` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mat_ens` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `matricule_cnps` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lieu_ens` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `civilite_ens` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tel_ens` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `adresse_ens` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_grade` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `indice_grille` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_fonction` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sexe_ens` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_naiss_ens` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mail_ens` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `num_cni` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tribu_ens` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ethnie_ens` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `situation_ens` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nb_enfants` tinyint unsigned DEFAULT '0',
  `nb_pers_charge` tinyint unsigned DEFAULT '0',
  `arrondissement_ens` int DEFAULT NULL,
  `statut_ens` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actif',
  `date_recrutement` date DEFAULT NULL,
  `mode_paiement` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nom_banque` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compte_bancaire` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lieu_origine_libre` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`matricule_ens`),
  KEY `id_grade` (`id_grade`,`id_fonction`),
  KEY `id_fonction` (`id_fonction`),
  KEY `arrondissement_ens` (`arrondissement_ens`),
  CONSTRAINT `fk_enseignant_grade` FOREIGN KEY (`id_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enseignat_classe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enseignat_classe` (
  `matricule_ens` int NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `matricule_ens_Classe_Annee` (`matricule_ens`,`IDClasses`,`val_annee`),
  KEY `IDClasses` (`IDClasses`),
  KEY `val_annee` (`val_annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enseignat_classe_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `enseignat_classe_arabe` (
  `matricule_ens` int NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `matricule_ens_Classe_Annee` (`matricule_ens`,`IDClasses`,`val_annee`),
  KEY `IDClasses` (`IDClasses`),
  KEY `val_annee` (`val_annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `etablissement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `etablissement` (
  `IDEtablissement` bigint NOT NULL DEFAULT '0',
  `Nom_Etab_Fr` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Nom_Etab_An` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Initial_Etab` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `Immatriculation_Etab` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `boite_postal` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ville_etab` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tel_etab` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `email_etab` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pays_etab_fr` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_etab_fr` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departement_fr` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrondissement_fr` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pays_etab_en` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_etab_en` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departement_en` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrondissement_en` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lieu_etab` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pays_etab_ar` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_ar` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `departement_ar` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `arrondissement_ar` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ecole_ar` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fonction_dirigeant_fr` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fonction_dirigeant_en` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fonction_dirigeant_ar` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_etab` longblob,
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `filigranne_etab` longblob,
  `signature` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  UNIQUE KEY `IDEtablissement` (`IDEtablissement`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `evaluation_annulee`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `evaluation_annulee` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_eleve` int unsigned NOT NULL,
  `id_seq` int NOT NULL,
  `val_annee` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `motif` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `annule_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eval_annule_eleve_seq_annee` (`id_eleve`,`id_seq`,`val_annee`),
  KEY `id_seq` (`id_seq`),
  KEY `fk_eval_annule_annee` (`val_annee`),
  KEY `fk_eval_annule_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_eval_annule_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_eval_annule_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exclusion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `exclusion` (
  `id_eleve` int unsigned NOT NULL,
  `id_trim` int NOT NULL,
  `classe` int NOT NULL,
  `nbre_jours` int DEFAULT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_exclusion_eleve_trim_annee` (`id_eleve`,`id_trim`,`val_annee`),
  KEY `val_annee` (`val_annee`),
  KEY `id_trim` (`id_trim`),
  KEY `classe` (`classe`),
  CONSTRAINT `fk_exclusion_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fonction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fonction` (
  `id_fonction` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_fonction`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
-- Socle des rôles (cf. bd/migration_v51.sql) — alimente le <select> de rôle
-- de pages/utilisateurs/liste.php. Une nouvelle école part de ce schéma.
INSERT IGNORE INTO `fonction` (`id_fonction`) VALUES
  ('DIRECTEUR'),('FONDATEUR'),('ENSEIGNANT'),('SECRETAIRE'),('COMPTABLE');
DROP TABLE IF EXISTS `grade_enseignant`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `grade_enseignant` (
  `code_grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_grade` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `salaire_base` decimal(12,2) NOT NULL DEFAULT '0.00',
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`code_grade`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groupe_competence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groupe_competence` (
  `id_groupe_comp` int NOT NULL AUTO_INCREMENT,
  `libelle_groupe_comp` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `langue` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id_groupe_comp`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groupe_competence_niveau`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groupe_competence_niveau` (
  `code_niveau` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_groupe_comp` int NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`code_niveau`,`id_groupe_comp`),
  KEY `id_groupe_comp` (`id_groupe_comp`),
  CONSTRAINT `fk_gcn_groupe` FOREIGN KEY (`id_groupe_comp`) REFERENCES `groupe_competence` (`id_groupe_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_gcn_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groupe_matiere_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `groupe_matiere_arabe` (
  `id_groupe` int NOT NULL AUTO_INCREMENT,
  `nom_groupe_fr` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nom_groupe_ar` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_groupe`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `indemnite_grade`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `indemnite_grade` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_grade` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `libelle_indemnite` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `code_grade` (`code_grade`),
  CONSTRAINT `fk_indemnite_grade` FOREIGN KEY (`code_grade`) REFERENCES `grade_enseignant` (`code_grade`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `info_supplementaires`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `info_supplementaires` (
  `id_info` int NOT NULL AUTO_INCREMENT,
  `dernier_etab` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `derniere_classe` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_rec` date DEFAULT NULL,
  `antecedent_med` mediumtext COLLATE utf8mb4_unicode_ci,
  `frequence` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autres_infos` mediumtext COLLATE utf8mb4_unicode_ci,
  `handicap` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nature_handicap` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `refugie` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type_refugie` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `indigent` varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cas_social` tinyint(1) NOT NULL DEFAULT '0',
  `pourcentage_reduction` decimal(5,2) NOT NULL DEFAULT '0.00',
  `id_eleve` int unsigned NOT NULL,
  PRIMARY KEY (`id_info`),
  UNIQUE KEY `uk_info_suppl_eleve` (`id_eleve`),
  CONSTRAINT `fk_info_suppl_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inscrire`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inscrire` (
  `id_eleve` int unsigned NOT NULL,
  `IDClasses` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `Date_Inscrire` date NOT NULL,
  `Statut_elv` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_inscrire_eleve_annee` (`id_eleve`,`val_annee`),
  KEY `Mat_elv_Classe_annee` (`IDClasses`,`val_annee`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_inscrire_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inscrire_ibfk_2` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inscrire_ibfk_3` FOREIGN KEY (`IDClasses`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ligne_bulletin_paie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ligne_bulletin_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_bulletin` int NOT NULL,
  `type_ligne` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_rubrique` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `libelle` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nb` decimal(10,2) DEFAULT NULL,
  `montant` decimal(12,2) NOT NULL,
  `base` decimal(12,2) DEFAULT NULL,
  `taux_pct` decimal(6,2) DEFAULT NULL,
  `ordre_affichage` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `id_bulletin` (`id_bulletin`),
  CONSTRAINT `fk_ligne_bulletin` FOREIGN KEY (`id_bulletin`) REFERENCES `bulletin_paie` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `matiere_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matiere_arabe` (
  `id_mat` int NOT NULL AUTO_INCREMENT,
  `matiere_fr` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `matiere_ar` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_groupe` int DEFAULT NULL,
  PRIMARY KEY (`id_mat`),
  KEY `fk_matarabe_groupe` (`id_groupe`),
  CONSTRAINT `fk_matarabe_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupe_matiere_arabe` (`id_groupe`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `matiere_niveau_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matiere_niveau_arabe` (
  `code_niveau` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_mat` int NOT NULL,
  `ordre` int NOT NULL DEFAULT '1',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`code_niveau`,`id_mat`),
  KEY `fk_matniv_mat` (`id_mat`),
  CONSTRAINT `fk_matniv_mat` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_matniv_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`LibelleNiveau`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `moyenne_annuelle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moyenne_annuelle` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `moy` float NOT NULL,
  `Nb_trim` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_moy_an_eleve_classe_annee` (`id_eleve`,`classe`,`val_annee`),
  KEY `mat_elv` (`classe`,`val_annee`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_moyenne_annuelle_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_annuelle_ibfk_2` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_annuelle_ibfk_3` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `moyenne_annuelle_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moyenne_annuelle_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `moy` float NOT NULL,
  `Nb_trim` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_moy_an_ar_eleve_classe_annee` (`id_eleve`,`classe`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_moyenne_annuelle_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_moyenne_annuelle_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_moyenne_annuelle_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `moyenne_sequence_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moyenne_sequence_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `id_seq` int NOT NULL,
  `classe` int NOT NULL,
  `moy` float NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_moy_seq_ar_eleve_seq_classe_annee` (`id_eleve`,`id_seq`,`classe`,`val_annee`),
  KEY `id_seq` (`id_seq`,`classe`,`val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  KEY `idx_moy_seq_ar_eleve` (`id_eleve`,`id_seq`,`classe`,`val_annee`),
  CONSTRAINT `fk_moyenne_sequence_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_sequence_arabe_ibfk_2` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_sequence_arabe_ibfk_3` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `moyenne_trimestre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moyenne_trimestre` (
  `id_eleve` int unsigned NOT NULL,
  `id_trim` int NOT NULL,
  `classe` int NOT NULL,
  `moy` decimal(4,2) NOT NULL,
  `classable` tinyint(1) NOT NULL DEFAULT '1',
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_moy_trim_eleve_trim_classe_annee` (`id_eleve`,`id_trim`,`classe`,`val_annee`),
  KEY `id_trim` (`id_trim`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_moyenne_trimestre_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_ibfk_2` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_ibfk_3` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_ibfk_4` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `moyenne_trimestre_arabe`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `moyenne_trimestre_arabe` (
  `id_eleve` int unsigned NOT NULL,
  `id_trim` int NOT NULL,
  `classe` int NOT NULL,
  `moy` decimal(4,2) NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  UNIQUE KEY `uk_moy_trim_ar_eleve_trim_classe_annee` (`id_eleve`,`id_trim`,`classe`,`val_annee`),
  KEY `id_trim` (`id_trim`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_moyenne_trimestre_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_arabe_ibfk_2` FOREIGN KEY (`id_trim`) REFERENCES `trimestre` (`id_trim`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_arabe_ibfk_3` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `moyenne_trimestre_arabe_ibfk_4` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `niveau`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `niveau` (
  `LibelleNiveau` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT '0',
  `Section` varchar(2) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fr',
  `OrdreNiveau` int NOT NULL DEFAULT '0',
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  UNIQUE KEY `LibelleNiveau` (`LibelleNiveau`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `obligation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `obligation` (
  `id_obligation` int NOT NULL AUTO_INCREMENT,
  `nom_obligation` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `montant_obligation` float NOT NULL,
  `niveau_obligation` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_obligation`),
  KEY `niveau_obligation` (`niveau_obligation`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `paiement_frais`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `paiement_frais` (
  `id_pay` int NOT NULL AUTO_INCREMENT,
  `id_versement` int DEFAULT NULL,
  `id_eleve` int unsigned NOT NULL,
  `classe` int NOT NULL,
  `val_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_obligation` int NOT NULL,
  `montant_paiement` float NOT NULL,
  `date_paiement` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_paiement` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode_paiement` enum('ESPECES','ORANGE_MONEY','MOMO','BANQUE','AUTRE') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ESPECES',
  `id_utilisateur` int DEFAULT NULL,
  PRIMARY KEY (`id_pay`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  KEY `id_obligation` (`id_obligation`),
  KEY `idx_paiement_eleve` (`id_eleve`),
  KEY `id_utilisateur` (`id_utilisateur`),
  KEY `idx_paiement_frais_id_versement` (`id_versement`),
  CONSTRAINT `fk_paiement_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_paiement_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `paiement_frais_ibfk_2` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `paiement_frais_ibfk_3` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=593 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `parent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `parent` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nom` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenom` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `profession` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `adresse` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sexe` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_eleve` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent_id_eleve` (`id_eleve`),
  CONSTRAINT `fk_parent_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pays` (
  `code_pays` int NOT NULL AUTO_INCREMENT,
  `nom_pays` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nationalite` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`code_pays`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `periode_paie`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `periode_paie` (
  `id` int NOT NULL AUTO_INCREMENT,
  `mois` tinyint NOT NULL,
  `annee` smallint NOT NULL,
  `libelle` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `statut` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Brouillon',
  `cree_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_validation` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_periode_mois_annee` (`mois`,`annee`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `question_secrete`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `question_secrete` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `region`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `region` (
  `id_region` int NOT NULL AUTO_INCREMENT,
  `intitule_region` varchar(25) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_pays` int NOT NULL,
  PRIMARY KEY (`id_region`),
  KEY `code_pays` (`code_pays`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `remboursement_avance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sequence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sequence` (
  `id_seq` int NOT NULL AUTO_INCREMENT,
  `libelle_seq` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `etat` int DEFAULT NULL,
  `id_trim` int NOT NULL,
  PRIMARY KEY (`id_seq`),
  KEY `id_trim` (`id_trim`),
  KEY `etat_seq` (`etat`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `signature_position`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_position` (
  `type_document` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `x_pct` decimal(6,3) NOT NULL,
  `y_pct` decimal(6,3) NOT NULL,
  `w_pct` decimal(6,3) NOT NULL,
  `h_pct` decimal(6,3) DEFAULT NULL,
  PRIMARY KEY (`type_document`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trimestre`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trimestre` (
  `id_trim` int NOT NULL AUTO_INCREMENT,
  `libelle_trim` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_annee` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id_trim`),
  KEY `IDAnnee_scolaire` (`id_annee`),
  CONSTRAINT `trimestre_ibfk_1` FOREIGN KEY (`id_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user` (
  `id_user` int NOT NULL AUTO_INCREMENT,
  `login_user` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pwd_user` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT '1',
  `derniere_connexion` datetime DEFAULT NULL,
  `matricule_ens` int NOT NULL,
  PRIMARY KEY (`id_user`),
  KEY `matricule_ens` (`matricule_ens`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_question_secrete`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_question_secrete` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `id_user` int NOT NULL,
  `id_question` int unsigned NOT NULL,
  `reponse_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `maj_le` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_question` (`id_user`,`id_question`),
  KEY `idx_uqs_question` (`id_question`),
  CONSTRAINT `fk_uqs_question` FOREIGN KEY (`id_question`) REFERENCES `question_secrete` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_uqs_user` FOREIGN KEY (`id_user`) REFERENCES `user` (`id_user`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=101 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- ── Licence (bd/lib/licence.php, migration v56) — ajoutées le 13/09/2026,
--    voir bd/migration_v56.sql pour la version idempotente appliquée aux
--    écoles existantes (mêmes définitions, dupliquées ici pour toute
--    NOUVELLE école créée directement avec ce schéma de référence).
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

CREATE TABLE `licence_securite` (
  `id` tinyint NOT NULL,
  `tentatives_echouees` int NOT NULL DEFAULT 0,
  `bloque_le` datetime DEFAULT NULL,
  `dernier_maintenant_vu` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `licence_securite` (`id`, `tentatives_echouees`, `bloque_le`, `dernier_maintenant_vu`) VALUES (1, 0, NULL, NULL);

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

