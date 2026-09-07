-- =====================================================================
--  jaynitaare_v2 — Migration v52 : configuration du format de matricule
--                                  par école
-- =====================================================================
--  Chaque école peut désormais choisir :
--   - le MODE d'attribution du matricule élève :
--       * 'auto'   : généré à l'enregistrement d'après un format (défaut,
--                    comportement historique),
--       * 'manuel' : champ libre saisi par l'utilisateur, éventuellement
--                    laissé vide (Mat_elv devient alors NULL).
--   - le FORMAT (mode auto) via des jetons :
--       {AA}     année scolaire sur 2 chiffres (ex. 25 pour 2025/2026)
--       {AAAA}   année scolaire sur 4 chiffres
--       {NIV}    'M' pour la maternelle, 'P' sinon
--       {SEQ}    numéro d'ordre, complété à `longueur_seq` chiffres
--       texte libre autorisé (préfixe, séparateurs…)
--     Le défaut '{AA}{NIV}{SEQ}' + longueur_seq=3 reproduit exactement
--     l'ancien gen_matricule() (ex. « 25P001 »).
--   - `sequence_par` : périmètre de la numérotation {SEQ}
--       'annee_niveau' : remise à 1 par (année, niveau) — défaut, historique
--       'annee'        : remise à 1 chaque année, tous niveaux confondus
--       'globale'      : jamais remise à zéro
--
--  Table à ligne unique (id=1). matricule_config() (fonctions.php) renvoie
--  des valeurs par défaut si la table ou la ligne est absente — aucune
--  dépendance au seed.
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `matricule_config` (
  `id`           tinyint(1) NOT NULL DEFAULT 1,
  `mode`         enum('auto','manuel') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `format`       varchar(60)  COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '{AA}{NIV}{SEQ}',
  `longueur_seq` tinyint(2)   NOT NULL DEFAULT 3,
  `sequence_par` enum('annee_niveau','annee','globale') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'annee_niveau',
  `maj_le`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `matricule_config` (`id`, `mode`, `format`, `longueur_seq`, `sequence_par`)
VALUES (1, 'auto', '{AA}{NIV}{SEQ}', 3, 'annee_niveau');

-- `eleve.Mat_elv` : varchar(10) NOT NULL DEFAULT '' UNIQUE -> varchar(30)
-- NULL. Nécessaire pour :
--   - les formats personnalisés plus longs que « 25P001 » (préfixe école,
--     séparateurs, {AAAA}…) ;
--   - le mode 'manuel' où le matricule peut être ABSENT : MySQL autorise
--     plusieurs NULL dans un index UNIQUE (mais un seul '' — d'où le NULL).
-- Idempotent : rejoue sans effet si déjà en varchar(30) NULL. La bascule
-- ''->NULL des lignes existantes se fait juste après (les ''  restants
-- casseraient l'unicité dès le 2e élève sans matricule).
ALTER TABLE `eleve` MODIFY `Mat_elv` varchar(30) COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL;
UPDATE `eleve` SET `Mat_elv` = NULL WHERE `Mat_elv` = '';
