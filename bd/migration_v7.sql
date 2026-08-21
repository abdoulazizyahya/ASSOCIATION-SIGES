-- =====================================================================
--  jaynitaare_v2 — Migration v7
--  Suite de migration_v1.sql à v6.sql.
--
--  Objet (piste arabe, module Notes/Bulletins APC — session 5) :
--   1) `moyenne_sequence_arabe` n'avait aucune contrainte UNIQUE
--      (id_eleve, id_seq, classe, val_annee) — seulement des index simples —
--      malgré des upserts nécessaires (ON DUPLICATE KEY UPDATE) pour mettre
--      en cache la moyenne par séquence, comme moyenne_sequence côté
--      français. Aucun doublon existant (vérifié avant migration), ajout
--      sans risque.
--   2) `moyenne_annuelle_arabe` n'existe pas du tout dans le schéma legacy
--      (contrairement à moyenne_annuelle côté français) — nouvelle table,
--      même structure que moyenne_annuelle.
--
--  À exécuter via bd/run_migration_v7.php.
-- =====================================================================

ALTER TABLE `moyenne_sequence_arabe`
  ADD UNIQUE KEY `uk_moy_seq_ar_eleve_seq_classe_annee` (`id_eleve`, `id_seq`, `classe`, `val_annee`);

CREATE TABLE IF NOT EXISTS `moyenne_annuelle_arabe` (
  `id_eleve`  INT UNSIGNED NOT NULL,
  `classe`    INT NOT NULL,
  `moy`       FLOAT NOT NULL,
  `Nb_trim`   INT NOT NULL,
  `val_annee` VARCHAR(10) NOT NULL,
  UNIQUE KEY `uk_moy_an_ar_eleve_classe_annee` (`id_eleve`, `classe`, `val_annee`),
  KEY `classe` (`classe`),
  KEY `val_annee` (`val_annee`),
  CONSTRAINT `fk_moyenne_annuelle_arabe_eleve` FOREIGN KEY (`id_eleve`) REFERENCES `eleve` (`id_eleve`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_moyenne_annuelle_arabe_classe` FOREIGN KEY (`classe`) REFERENCES `classe` (`IDClasses`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_moyenne_annuelle_arabe_annee` FOREIGN KEY (`val_annee`) REFERENCES `annee_scolaire` (`val_annee`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
