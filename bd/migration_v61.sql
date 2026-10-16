-- =====================================================================
--  SIGES — Migration v61 : paiements reportés après changement de classe
-- =====================================================================
--  paiement_frais.classe fige la classe du jour du versement, et les
--  états financiers (état par classe, impayés, répartition…) filtrent
--  sur cette colonne. Jusqu'ici, changer la classe d'un élève ne mettait
--  à jour que `inscrire` : le paiement restait compté dans l'ancienne
--  classe et l'élève paraissait impayé dans la nouvelle (seul le détail
--  de sa fiche était juste). Corrigé dans le code le 16/10/2026
--  (finances_suivre_changement_classe(), noyau/fonctions.php, appelée par
--  primaire/pages/eleves/save.php) ; cette migration répare l'existant :
--
--  1. chaque paiement de l'année passe dans la classe ACTUELLE de
--     l'élève pour cette même année ;
--  2. les frais étant définis par niveau, un paiement d'un frais d'un
--     autre niveau que celui de la classe actuelle est rattaché au frais
--     du MÊME NOM du niveau actuel (ex. APEE niveau II → APEE niveau I),
--     quand il existe ; sinon il est laissé tel quel.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent (rejouer la
--  migration ne change plus rien une fois les données cohérentes).
-- =====================================================================

-- 1. Classe du paiement = classe actuelle de l'élève pour l'année du paiement
UPDATE `paiement_frais` p
  JOIN `inscrire` i ON i.id_eleve = p.id_eleve
                   AND i.val_annee COLLATE utf8mb4_unicode_ci = p.val_annee COLLATE utf8mb4_unicode_ci
   SET p.classe = i.IDClasses
 WHERE p.classe <> i.IDClasses;

-- 2. Frais de l'ancien niveau → frais du même nom dans le niveau de la classe actuelle
UPDATE `paiement_frais` p
  JOIN `inscrire` i    ON i.id_eleve = p.id_eleve
                      AND i.val_annee COLLATE utf8mb4_unicode_ci = p.val_annee COLLATE utf8mb4_unicode_ci
  JOIN `classe` c      ON c.IDClasses = i.IDClasses
  JOIN `obligation` o  ON o.id_obligation = p.id_obligation
  JOIN `obligation` o2 ON o2.niveau_obligation COLLATE utf8mb4_unicode_ci = c.Niveau COLLATE utf8mb4_unicode_ci
                      AND UPPER(TRIM(o2.nom_obligation)) COLLATE utf8mb4_unicode_ci = UPPER(TRIM(o.nom_obligation)) COLLATE utf8mb4_unicode_ci
   SET p.id_obligation = o2.id_obligation
 WHERE o.niveau_obligation COLLATE utf8mb4_unicode_ci <> c.Niveau COLLATE utf8mb4_unicode_ci;
