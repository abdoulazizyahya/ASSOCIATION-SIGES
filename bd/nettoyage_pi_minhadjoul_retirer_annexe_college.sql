-- =====================================================================
--  bd/nettoyage_pi_minhadjoul_retirer_annexe_college.sql
--
--  Base : promeducam_pi_minhadjoul_mouslim  (GSB PI MINHADJOUL MOUSLIM)
--
--  Cette base contient, en plus de ses propres données, celles de deux
--  écoles désormais séparées :
--    * GSBPI MINHADJOUL MOUSLIM ANNEXE  (promeducam_gsbpi_minhadjoul_mouslim_annexe)
--        -> classes 40 CP C, 41 CE1 C, 42 CE2 C, 43 CM1 C, 44 CM2 C, 46 SIL C
--        -> 165 élèves, 82 paiements   (identiques à ceux de la base annexe)
--    * COLLEGE MINHADJOUL MOUSLIM       (promeducam_minhadjoul_mouslim)
--        -> classes 49 6ème, 50 5ème, 51 4ème
--        -> 75 élèves, 72 paiements    (le collège n'a PAS encore de paiements
--           dans sa propre base : ces 72 lignes ne seront plus nulle part)
--
--  Le script supprime, dans la base PI seulement, les élèves inscrits dans
--  ces 9 classes (+ tout ce qui s'y rattache : paiements, inscriptions,
--  notes/moyennes, absences…) puis les 9 classes et leurs disciplines.
--  Il ne touche PAS aux 2 élèves sans inscription, ni aux autres classes
--  (dont 45, 47, 52), ni aux utilisateurs, obligations, enseignants,
--  année scolaire, licence.
--
--  Vérifié avant rédaction : aucun élève n'est inscrit à la fois dans ces
--  classes et dans une autre (aucun risque de supprimer un élève du PI).
--
--  UTILISATION
--    1. Sauvegarde :
--         mysqldump -uroot promeducam_pi_minhadjoul_mouslim > pi_minhadjoul_avant_nettoyage.sql
--    2. Exécuter ce fichier : les contrôles « avant » s'affichent, tout est
--       fait dans UNE transaction et se termine par un ROLLBACK.
--       Vérifier les nombres, puis remplacer ROLLBACK par COMMIT (dernière
--       ligne) et relancer pour valider.
--
--    Attendu (avant) : 9 classes, 240 élèves, 154 paiements, 240 inscriptions
--    Attendu (après) : 0 dans les 4 contrôles, 737 élèves restants (977-240),
--                      262 paiements restants (416-154).
-- =====================================================================

USE `promeducam_pi_minhadjoul_mouslim`;

START TRANSACTION;

-- Classes visées
CREATE TEMPORARY TABLE tmp_classes_cibles (IDClasses INT PRIMARY KEY);
INSERT INTO tmp_classes_cibles (IDClasses) VALUES
  (40), (41), (42), (43), (44), (46),   -- annexe
  (49), (50), (51);                     -- collège

-- Élèves visés = inscrits dans ces classes (jamais ailleurs, cf. en-tête)
CREATE TEMPORARY TABLE tmp_eleves_cibles (id_eleve INT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_eleves_cibles (id_eleve)
  SELECT DISTINCT id_eleve FROM inscrire
  WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);

-- Contrôles AVANT (une seule référence à la table temporaire par requête)
SET @c = (SELECT COUNT(*) FROM classe WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles));
SET @e = (SELECT COUNT(*) FROM tmp_eleves_cibles);
SET @p = (SELECT COUNT(*) FROM paiement_frais p JOIN tmp_eleves_cibles t ON t.id_eleve = p.id_eleve);
SET @i = (SELECT COUNT(*) FROM inscrire i JOIN tmp_eleves_cibles t ON t.id_eleve = i.id_eleve);
SELECT @c AS classes, @e AS eleves, @p AS paiements, @i AS inscriptions;

-- 1. Tout ce qui dépend des élèves (enfants d'abord)
DELETE FROM paiement_frais              WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM absence                     WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM absence_justifiee           WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM absence_justifiee_arabe     WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM composer_sequence           WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM composer_sequence_arabe     WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM decision_conseil            WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM decision_conseil_annuel     WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM decision_conseil_annuel_arabe WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM decision_conseil_arabe      WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM dossier_eleve               WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM evaluation_annulee          WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM exclusion                   WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM info_supplementaires        WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM moyenne_annuelle            WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM moyenne_annuelle_arabe      WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM moyenne_sequence_arabe      WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM moyenne_trimestre           WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM moyenne_trimestre_arabe     WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM parent                      WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);
DELETE FROM inscrire                    WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);

-- 2. Les élèves eux-mêmes
DELETE FROM eleve WHERE id_eleve IN (SELECT id_eleve FROM tmp_eleves_cibles);

-- 3. Ce qui dépend des classes, puis les classes
DELETE FROM discipline             WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM discipline_arabe       WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM dispenser              WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM enseignat_classe       WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM enseignat_classe_arabe WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM critere_conseil        WHERE id_classe IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM critere_conseil_arabe  WHERE id_classe IN (SELECT IDClasses FROM tmp_classes_cibles);
DELETE FROM classe                 WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles);

-- Contrôles APRÈS (les 4 premiers doivent être à 0) + ce qui reste
SET @c = (SELECT COUNT(*) FROM classe WHERE IDClasses IN (SELECT IDClasses FROM tmp_classes_cibles));
SET @e = (SELECT COUNT(*) FROM eleve x JOIN tmp_eleves_cibles t ON t.id_eleve = x.id_eleve);
SET @p = (SELECT COUNT(*) FROM paiement_frais p JOIN tmp_eleves_cibles t ON t.id_eleve = p.id_eleve);
SET @i = (SELECT COUNT(*) FROM inscrire i JOIN tmp_eleves_cibles t ON t.id_eleve = i.id_eleve);
SELECT @c AS classes_restantes, @e AS eleves_restants, @p AS paiements_restants, @i AS inscriptions_restantes,
       (SELECT COUNT(*) FROM eleve) AS total_eleves_base,
       (SELECT COUNT(*) FROM paiement_frais) AS total_paiements_base,
       (SELECT COUNT(*) FROM classe) AS total_classes_base;

DROP TEMPORARY TABLE tmp_eleves_cibles;
DROP TEMPORARY TABLE tmp_classes_cibles;

ROLLBACK;   -- remplacer par COMMIT; une fois les nombres vérifiés
