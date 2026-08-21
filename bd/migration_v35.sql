-- =====================================================================
--  jaynitaare_v2 — Migration v35 : bulletin de paie au format CNPS
--  (modèle fourni : BD JAYNITARE/modele/salaire.pdf, style CAMTEL)
-- =====================================================================
--  Le bulletin de paie généré (pdf/bulletin_paie.php) est refondu pour
--  suivre la mise en page d'un vrai bulletin CNPS camerounais : un seul
--  tableau Code/Rubrique/Nb/Unité/Base/Taux %/Gain/Retenue (au lieu de
--  deux blocs Gains/Retenues séparés), en-tête administratif complet
--  (matricule CNPS, banque, indice de grille, personnes à charge...),
--  montant en toutes lettres, bloc "Éléments de présence & rubriques
--  indicatives" (cotisations patronales, informatif).
--
--  1. `enseignant` : champs administratifs manquants pour l'en-tête.
--  2. `ligne_bulletin_paie` : détail par rubrique (code/nb/base/taux %),
--     en plus du libellé+montant déjà existants — colonnes NULLABLE,
--     lignes déjà générées (libellé+montant seuls) restent valides,
--     simplement affichées sans ces détails.
-- =====================================================================

-- 1. Champs administratifs sur enseignant
ALTER TABLE `enseignant`
  ADD COLUMN `matricule_cnps`  varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mat_ens`,
  ADD COLUMN `nb_enfants`      tinyint unsigned DEFAULT 0 AFTER `situation_ens`,
  ADD COLUMN `nb_pers_charge`  tinyint unsigned DEFAULT 0 AFTER `nb_enfants`,
  ADD COLUMN `indice_grille`   varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `id_grade`,
  ADD COLUMN `nom_banque`      varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `mode_paiement`;

-- 2. Détail par rubrique sur les lignes de bulletin (tout NULLABLE — une
--    ligne simple libellé+montant, sans ces détails, reste parfaitement
--    valide et s'affiche juste sans code/base/taux dans le nouveau tableau).
ALTER TABLE `ligne_bulletin_paie`
  ADD COLUMN `code_rubrique` varchar(10)    COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `type_ligne`,
  ADD COLUMN `nb`            decimal(10,2)  DEFAULT NULL AFTER `libelle`,
  ADD COLUMN `base`          decimal(12,2)  DEFAULT NULL AFTER `montant`,
  ADD COLUMN `taux_pct`      decimal(6,2)   DEFAULT NULL AFTER `base`;
