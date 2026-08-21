-- =====================================================================
--  jaynitaare_v2 — Migration v38 : Notes justifiées (absences lors d'une
--  évaluation) — piste française (compétences) et arabe (matières)
-- =====================================================================
--  Demande explicite du 16/08/2026 : reproduire pages/absences/index.php
--  d'ABZ_MBE (justifier l'absence d'un élève lors de l'évaluation d'une
--  matière/séquence, pour ne pas lui compter un zéro faute de note) —
--  adapté au modèle réel de jaynitaare : compétences+séquence côté
--  français (composer_sequence), matières+séquence côté arabe
--  (composer_sequence_arabe). Deux tables séparées plutôt que réutiliser
--  composer_sequence_arabe.etat_composition/.justification (colonnes
--  legacy jamais utilisées, 100% vides sur les 5754 lignes réelles,
--  vérifié) : une ligne composer_sequence(_arabe) n'existe QUE si une note
--  a été saisie, alors que la justification doit précisément pouvoir
--  s'appliquer à un élève SANS aucune ligne — upserter une ligne "vide"
--  dans la table de notes pour y accrocher une justification aurait
--  mélangé deux responsabilités distinctes ; une table dédiée, jamais
--  consultée par la saisie de notes elle-même, est plus sûre.
--
--  Effet sur le calcul (uniquement piste française, migration_v37) : pour
--  une compétence dont le zéro automatique se déclencherait (participation
--  classe >= 50%, élève sans note), une absence justifiée sur au moins une
--  des séquences du trimestre exempte l'élève du zéro — la compétence est
--  alors simplement ignorée pour lui ce trimestre (ni notée, ni zéro),
--  voir notes_apc.php::calculer_moyenne_trimestre_eleve(). N'affecte PAS
--  l'éligibilité au classement (`classable`) — décision volontaire, portée
--  identique à la page ABZ_MBE reproduite ("comptera 0" évité, rien de
--  plus). Piste arabe : PAS de zéro automatique existant (vérifié, voir
--  en-tête notes_apc_arabe.php — une matière non composée est déjà
--  simplement exclue du coefficient, jamais zéro) — la table arabe est donc
--  purement un registre administratif (pourquoi cette note manque), sans
--  effet sur le calcul de moyenne.
-- =====================================================================

CREATE TABLE `absence_justifiee` (
  `id_eleve`       int unsigned NOT NULL,
  `id_comp`        int NOT NULL,
  `id_seq`         int NOT NULL,
  `IDClasses`      int NOT NULL,
  `val_annee`      varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `justifie`       tinyint(1) NOT NULL DEFAULT 0,
  `raison`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `modifie_le`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`id_comp`,`id_seq`),
  KEY `idx_absjust_classe_seq` (`IDClasses`,`id_seq`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_absjust_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_comp` FOREIGN KEY (`id_comp`) REFERENCES `competence` (`id_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjust_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pas de val_annee (comme composer_sequence_arabe — l'année est déjà
-- portée par id_seq via sequence.id_trim/trimestre.id_annee).
CREATE TABLE `absence_justifiee_arabe` (
  `id_eleve`       int unsigned NOT NULL,
  `id_mat`         int NOT NULL,
  `id_seq`         int NOT NULL,
  `classe`         int NOT NULL,
  `justifie`       tinyint(1) NOT NULL DEFAULT 0,
  `raison`         varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_utilisateur` int DEFAULT NULL,
  `modifie_le`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_eleve`,`id_mat`,`id_seq`),
  KEY `idx_absjustar_classe_seq` (`classe`,`id_seq`),
  KEY `id_utilisateur` (`id_utilisateur`),
  CONSTRAINT `fk_absjustar_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_mat` FOREIGN KEY (`id_mat`) REFERENCES `matiere_arabe` (`id_mat`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_seq` FOREIGN KEY (`id_seq`) REFERENCES `sequence` (`id_seq`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_absjustar_utilisateur` FOREIGN KEY (`id_utilisateur`) REFERENCES `user` (`id_user`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
