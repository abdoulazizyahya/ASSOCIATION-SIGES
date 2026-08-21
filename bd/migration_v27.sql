-- =====================================================================
--  jaynitaare_v2 — Migration v27 : nettoyage de la BD (analyse du 12/08/2026)
-- =====================================================================
--  Sauvegarde préalable (structure + données) :
--  bd/backup_tables_mortes_avant_migration_v27_*.sql
--  (eleve_arabe, document, note_trimestrielle)
--
--  1. eleve_arabe → absorbée dans `eleve`
--     Ne contenait que le nom de l'élève en écriture arabe (Mat_elv,
--     Nom_elv, IDClasses, val_annee), SANS lien FK vers `eleve.id_eleve`
--     — un doublon à plat, non rattaché au dossier de l'élève. On
--     rajoute une colonne dédiée sur `eleve` et on y recopie la valeur
--     (appariement par Mat_elv) avant de supprimer la table.
--     224 élèves sur 227 récupèrent leur nom arabe ; les 16 lignes
--     orphelines de eleve_arabe (Mat_elv sans élève correspondant,
--     anciens élèves / doublons de saisie) restent dans la sauvegarde
--     si besoin de les ressaisir manuellement.
--
--  2. document (1 ligne, sans clé primaire ni clé étrangère)
--     Jamais lue ni écrite par l'application — supprimée.
--
--  3. note_trimestrielle (0 ligne, jamais alimentée)
--     Remplacée dans les faits par composer_sequence + moyenne_trimestre.
--     Seule mention dans le code : un commentaire de
--     pages/eleves/supprimer.php — aucune lecture/écriture réelle.
-- =====================================================================

-- 1a. Nouvelle colonne sur eleve
ALTER TABLE `eleve`
  ADD COLUMN `Nom_arabe_elv` varchar(250) COLLATE utf8mb4_unicode_ci DEFAULT NULL
  AFTER `Nom_elv`;

-- 1b. Reprise des données (une ligne par Mat_elv ; les doublons de
--     eleve_arabe portent le même texte, MIN() suffit à en choisir une)
UPDATE `eleve` e
JOIN (
    SELECT `Mat_elv`, MIN(`Nom_elv`) AS nom_ar
    FROM `eleve_arabe`
    GROUP BY `Mat_elv`
) x ON x.`Mat_elv` = e.`Mat_elv`
SET e.`Nom_arabe_elv` = x.nom_ar;

-- 1c. Suppression de l'ancienne table
DROP TABLE `eleve_arabe`;

-- 2 et 3. Tables mortes
DROP TABLE IF EXISTS `document`;
DROP TABLE IF EXISTS `note_trimestrielle`;
