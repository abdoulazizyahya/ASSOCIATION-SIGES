-- =====================================================================
--  jaynitaare_v2 — Migration v31 : etablissement_arabe absorbée dans
--  etablissement
-- =====================================================================
--  `etablissement_arabe` (1 seule ligne, PK `mat` sans lien réel vers
--  `etablissement.IDEtablissement`) ne contient QUE l'en-tête officiel
--  bilingue FR/AR utilisé par les 3 générateurs PDF de la piste arabe
--  (pdf/bulletin_trimestriel_arabe.php, pdf/bulletin_annuel_arabe.php,
--  pdf/certificat_tableau_honneur_arabe.php, via pdf/header_pdf_tcpdf.php)
--  — jamais écrite nulle part dans le code (aucun INSERT/UPDATE trouvé,
--  seulement `SELECT * FROM etablissement_arabe LIMIT 1`). Les 7 colonnes
--  `*_fr` ne sont PAS redondantes avec les colonnes existantes de
--  `etablissement` (ex. `delegation_reg_fr` = "DELEGATION REGIONALE DE
--  L'ADAMAOUA", formaté pour l'en-tête, alors que `region_etab_fr` =
--  "REGION DE L'ADAMAOUA" et que `delegation_regional_fr`/
--  `delegation_departemental_fr` sont vides sur la ligne actuelle) :
--  les 14 colonnes (7 fr + 7 ar) sont donc reprises telles quelles.
--
--  `etablissement` et `etablissement_arabe` n'ont chacune qu'UNE seule
--  ligne (fiche unique de l'établissement) : la reprise de données se
--  fait donc par simple UPDATE croisé, sans clé de correspondance.
-- =====================================================================

-- 1. Nouvelles colonnes sur etablissement (mêmes noms/types que sur
--    etablissement_arabe, pour un renommage de code minimal)
ALTER TABLE `etablissement`
  ADD COLUMN `republique_fr`      varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `lieu_etab`,
  ADD COLUMN `devise_fr`          varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `republique_fr`,
  ADD COLUMN `ministere_fr`       varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `devise_fr`,
  ADD COLUMN `delegation_reg_fr`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ministere_fr`,
  ADD COLUMN `delegation_dep_fr`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `delegation_reg_fr`,
  ADD COLUMN `arrondissement_fr`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `delegation_dep_fr`,
  ADD COLUMN `ecole_fr`           varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `arrondissement_fr`,
  ADD COLUMN `republique_ar`      varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ecole_fr`,
  ADD COLUMN `devise_ar`          varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `republique_ar`,
  ADD COLUMN `ministere_ar`       varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `devise_ar`,
  ADD COLUMN `delegation_reg_ar`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `ministere_ar`,
  ADD COLUMN `delegation_dep_ar`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `delegation_reg_ar`,
  ADD COLUMN `arrondissement_ar`  varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `delegation_dep_ar`,
  ADD COLUMN `ecole_ar`           varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `arrondissement_ar`;

-- 2. Reprise des données (ligne unique de chaque côté -> UPDATE croisé)
UPDATE `etablissement` e, `etablissement_arabe` a
SET e.republique_fr     = a.republique_fr,
    e.devise_fr         = a.devise_fr,
    e.ministere_fr       = a.ministere_fr,
    e.delegation_reg_fr = a.delegation_reg_fr,
    e.delegation_dep_fr = a.delegation_dep_fr,
    e.arrondissement_fr = a.arrondissement_fr,
    e.ecole_fr          = a.ecole_fr,
    e.republique_ar     = a.republique_ar,
    e.devise_ar         = a.devise_ar,
    e.ministere_ar      = a.ministere_ar,
    e.delegation_reg_ar = a.delegation_reg_ar,
    e.delegation_dep_ar = a.delegation_dep_ar,
    e.arrondissement_ar = a.arrondissement_ar,
    e.ecole_ar          = a.ecole_ar;

-- 3. Suppression de l'ancienne table
DROP TABLE `etablissement_arabe`;
