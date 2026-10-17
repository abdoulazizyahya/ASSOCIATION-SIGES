-- =====================================================================
--  SIGES — Migration SECONDAIRE v20 : chef d'établissement et fondateur
-- =====================================================================
--  Demandes du 17/10/2026 :
--   • au secondaire, pas de « directeur » : le chef d'établissement est le
--     PROVISEUR (école publique) ou le PRINCIPAL (école privée) ;
--   • une école publique n'a pas de fondateur, donc pas de compte fondateur ;
--     une école privée garde le sien ;
--   • Lycée Technique de Mbé : DJAFAROU reste enseignant, la fiche du
--     proviseur est celle de LENG EKOSSE Luc (compte « proviseur »).
--
--  Un compte retiré est SUPPRIMÉ s'il n'est rattaché à aucune donnée
--  (paiements, paie, demandes, notifications…), sinon seulement DÉSACTIVÉ.
--  Les mots de passe ne changent pas. Le compte propriétaire n'est jamais touché.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

SET @public := (SELECT statut = 'public' FROM `etablissement` LIMIT 1);

-- ── Comptes à retirer : fondateur d'une école publique ; « directeur » sans fiche
--    d'une école publique qui a déjà un autre compte proviseur ───────────────
DROP TEMPORARY TABLE IF EXISTS `tmp_v20_retrait`;
CREATE TEMPORARY TABLE `tmp_v20_retrait` (id int unsigned PRIMARY KEY);

INSERT IGNORE INTO `tmp_v20_retrait` (id)
SELECT u.id FROM `utilisateur` u
 WHERE @public = 1 AND u.compte_systeme = 0 AND u.role = 'FONDATEUR';

INSERT IGNORE INTO `tmp_v20_retrait` (id)
SELECT u.id FROM `utilisateur` u
 WHERE @public = 1 AND u.compte_systeme = 0 AND u.role = 'PROVISEUR'
   AND LOWER(u.login) = 'directeur' AND u.matricule_ens IS NULL
   AND EXISTS (SELECT 1 FROM (SELECT DISTINCT id FROM `utilisateur` WHERE role = 'PROVISEUR' AND compte_systeme = 0) p WHERE p.id <> u.id);

-- comptes rattachés à des données : désactivés seulement
DROP TEMPORARY TABLE IF EXISTS `tmp_v20_garde`;
CREATE TEMPORARY TABLE `tmp_v20_garde` (id int unsigned PRIMARY KEY);
INSERT IGNORE INTO `tmp_v20_garde` (id) SELECT t.id FROM `tmp_v20_retrait` t WHERE
       EXISTS (SELECT 1 FROM `avance_salaire`            x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `bulletin_paie`             x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `conge_enseignant`          x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `demande_document`          x WHERE x.id_censeur = t.id OR x.id_proviseur = t.id)
    OR EXISTS (SELECT 1 FROM `depense_privee`            x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `dossier_eleve`             x WHERE x.ajoute_par = t.id)
    OR EXISTS (SELECT 1 FROM `notification`              x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `paiement_frais`            x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `paiement_prive`            x WHERE x.id_utilisateur = t.id)
    OR EXISTS (SELECT 1 FROM `reglage_validite_document` x WHERE x.maj_par = t.id)
    OR EXISTS (SELECT 1 FROM `trimestre_annulation`      x WHERE x.id_utilisateur = t.id);

UPDATE `utilisateur` u JOIN `tmp_v20_garde` g ON g.id = u.id SET u.actif = 0;
DELETE q FROM `utilisateur_question_secrete` q
  JOIN `tmp_v20_retrait` t ON t.id = q.id_utilisateur
  LEFT JOIN `tmp_v20_garde` g ON g.id = t.id WHERE g.id IS NULL;
DELETE u FROM `utilisateur` u
  JOIN `tmp_v20_retrait` t ON t.id = u.id
  LEFT JOIN `tmp_v20_garde` g ON g.id = t.id WHERE g.id IS NULL;

DROP TEMPORARY TABLE IF EXISTS `tmp_v20_garde`;
DROP TEMPORARY TABLE IF EXISTS `tmp_v20_retrait`;

-- ── École privée : le compte « directeur » devient le compte « principal » ──
UPDATE `utilisateur` u
   SET u.login = 'principal',
       u.prenom = IF(UPPER(u.nom) IN ('DIRECTEUR', 'DIRECTION (PRINCIPAL)', 'DIRECTION'), 'Général', u.prenom),   -- avant nom : affectations de gauche à droite
       u.nom = IF(UPPER(u.nom) IN ('DIRECTEUR', 'DIRECTION (PRINCIPAL)', 'DIRECTION'), 'Principal', u.nom)
 WHERE @public = 0 AND u.compte_systeme = 0 AND u.role = 'PROVISEUR' AND LOWER(u.login) = 'directeur'
   AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT login FROM `utilisateur`) x WHERE LOWER(x.login) = 'principal');

UPDATE `enseignant` e
  JOIN `utilisateur` u ON u.matricule_ens = e.matricule_ens AND u.role = 'PROVISEUR'
   SET e.nom_ens = 'PRINCIPAL', e.id_fonction = 'Principal'
 WHERE @public = 0 AND UPPER(e.nom_ens) = 'DIRECTEUR';

-- ── École publique : un compte « directeur » resté seul chef devient « proviseur » ──
UPDATE `utilisateur` u
   SET u.login = 'proviseur',
       u.prenom = IF(UPPER(u.nom) IN ('DIRECTEUR', 'DIRECTION'), 'Général', u.prenom),   -- avant nom : affectations de gauche à droite
       u.nom = IF(UPPER(u.nom) IN ('DIRECTEUR', 'DIRECTION'), 'Proviseur', u.nom)
 WHERE @public = 1 AND u.compte_systeme = 0 AND u.role = 'PROVISEUR' AND LOWER(u.login) = 'directeur'
   AND NOT EXISTS (SELECT 1 FROM (SELECT DISTINCT login FROM `utilisateur`) x WHERE LOWER(x.login) = 'proviseur');

-- ── Lycée Technique de Mbé ───────────────────────────────────────────────
SET @ltm := (SELECT COUNT(*) FROM `etablissement` WHERE sigle = 'LTM' AND nom_fr LIKE 'LYC%TECHNIQUE DE MB%');

UPDATE `enseignant` SET id_fonction = 'ENSEIGNANT'
 WHERE @ltm > 0 AND nom_ens = 'DJAFAROU' AND UPPER(id_fonction) = 'PROVISEUR';

UPDATE `enseignant` e
  JOIN `utilisateur` u ON u.matricule_ens = e.matricule_ens AND LOWER(u.login) = 'proviseur' AND u.role = 'PROVISEUR'
   SET e.civilite_ens = 'M.', e.nom_ens = 'LENG EKOSSE', e.prenom_ens = 'Luc', e.sexe_ens = 'Masculin', e.id_fonction = 'Proviseur',
       u.nom = 'LENG EKOSSE', u.prenom = 'Luc'
 WHERE @ltm > 0 AND UPPER(e.nom_ens) IN ('PROVISEUR', 'LENG EKOSSE');
