-- =====================================================================
--  jaynitaare_v2 — Migration v29 : optimisation capacité/mémoire/requêtes
-- =====================================================================
--  Analyse du 13/08/2026 sur les 41 tables restantes après v27/v28.
--  Aucune fusion de table proposée ici : les paires FR/AR (moyenne_*,
--  decision_conseil*, enseignat_classe*...) ont des volumes trop faibles
--  (0 à 716 lignes) pour que fusionner apporte un gain mesurable de
--  capacité — le coût (réécrire des dizaines de requêtes PDF/pages avec
--  un discriminant langue) dépasserait largement le bénéfice. Les deux
--  vrais gisements identifiés :
--
--  A. INDEX REDONDANTS — un index simple déjà couvert par le préfixe
--     gauche d'un index composé sur la même table (ou carrément dupliqué)
--     n'apporte aucune requête supplémentaire servie, seulement du
--     stockage et un coût d'écriture (chaque INSERT/UPDATE/DELETE doit
--     maintenir tous les index) en plus. Détecté par comparaison
--     systématique de information_schema.statistics, table par table.
--
--  B. `moyenne_trimestre(_arabe).moy` en varchar(10) → decimal(4,2)
--     Incohérent avec moyenne_annuelle.moy (déjà FLOAT). Cause un tri
--     lexicographique si on oublie le cast, bug déjà rencontré et
--     contourné par (mt.moy + 0) dans notes_apc.php / notes_apc_arabe.php
--     (voir commentaires à ces endroits). decimal(4,2) est aussi le type
--     déjà utilisé pour toutes les moyennes/seuils de critere_conseil —
--     on aligne sur la convention existante plutôt que d'en inventer une.
--     6 lignes historiques de moyenne_trimestre_arabe portent moy=''
--     (jamais produites par le code actuel, qui supprime la ligne plutôt
--     que d'écrire une valeur vide) — nettoyées avant conversion.
-- =====================================================================

-- ── A. Index redondants ────────────────────────────────────────────
-- absence : `mat_elv` et `id_trim` indexent tous deux la seule colonne id_trim
ALTER TABLE `absence` DROP KEY `mat_elv`;

-- moyenne_sequence_arabe : `mat_elv` et `id_seq` sont un composite identique
-- (id_seq,classe,val_annee)
ALTER TABLE `moyenne_sequence_arabe` DROP KEY `mat_elv`;

-- moyenne_annuelle : `classe` seul est couvert par le préfixe de `mat_elv` (classe,val_annee)
ALTER TABLE `moyenne_annuelle` DROP KEY `classe`;

-- discipline : `IDClasses` seul est couvert par le préfixe de `IDClass_comp_annee`
ALTER TABLE `discipline` DROP KEY `IDClasses`;

-- enseignat_classe(_arabe) : `matricule_ens` seul est couvert par le préfixe
-- de `matricule_ens_Classe_Annee`
ALTER TABLE `enseignat_classe` DROP KEY `matricule_ens`;
ALTER TABLE `enseignat_classe_arabe` DROP KEY `matricule_ens`;

-- inscrire : `idx_inscrire_eleve` couvert par `uk_inscrire_eleve_annee`,
-- `IDClasses` couvert par `Mat_elv_Classe_annee`
ALTER TABLE `inscrire` DROP KEY `idx_inscrire_eleve`;
ALTER TABLE `inscrire` DROP KEY `IDClasses`;

-- exclusion : `mat_elv_2` = UNIQUE(id_trim, val_annee) SANS id_eleve — en
-- plus d'être redondant avec l'index simple id_trim, cette contrainte
-- interdirait à deux élèves différents d'avoir chacun une exclusion le
-- même trimestre/année (0 ligne actuellement, jamais bloqué en pratique
-- mais casserait la fonctionnalité dès la première utilisation réelle).
ALTER TABLE `exclusion` DROP KEY `mat_elv_2`;

-- ── B. Type numérique pour moyenne_trimestre(_arabe).moy ────────────
DELETE FROM `moyenne_trimestre_arabe` WHERE `moy` = '';

ALTER TABLE `moyenne_trimestre`
  MODIFY COLUMN `moy` decimal(4,2) NOT NULL;

ALTER TABLE `moyenne_trimestre_arabe`
  MODIFY COLUMN `moy` decimal(4,2) NOT NULL;
