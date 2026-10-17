-- =====================================================================
--  SIGES — Migration SECONDAIRE v18 : rôle du compte = fonction SG / Censeur
-- =====================================================================
--  Demande du 17/10/2026 : « lorsque la fonction est SG / Censeur, considère
--  son compte comme son rôle ». Le code le fait désormais à chaque
--  enregistrement de fiche (sec_aligner_role_fiche(), noyau/fonctions.php) ;
--  cette migration aligne les comptes existants : un compte ENSEIGNANT dont
--  la fiche porte la fonction SG (ou « surveillant… ») devient SG, Censeur
--  devient CENSEUR. Aucun autre rôle n'est touché (administrateur, proviseur,
--  secrétaire, intendant, compte propriétaire).
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

UPDATE `utilisateur` u
  JOIN `enseignant` e ON e.matricule_ens = u.matricule_ens
   SET u.role = 'SG'
 WHERE u.role = 'ENSEIGNANT' AND u.compte_systeme = 0
   AND (LOWER(TRIM(e.id_fonction)) = 'sg' OR LOWER(e.id_fonction) LIKE '%surveillant%');

UPDATE `utilisateur` u
  JOIN `enseignant` e ON e.matricule_ens = u.matricule_ens
   SET u.role = 'CENSEUR'
 WHERE u.role = 'ENSEIGNANT' AND u.compte_systeme = 0
   AND LOWER(e.id_fonction) LIKE '%censeur%';
