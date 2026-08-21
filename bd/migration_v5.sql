-- =====================================================================
--  jaynitaare_v2 — Migration v5
--  Suite de migration_v1.sql à v4.sql.
--
--  Objet (demande utilisateur du 10/08/2026), sur la table `eleve`
--  (227 fiches réelles — sauvegardée au préalable via mysqldump, voir
--  bd/backup_eleve_avant_migration_v5_*.sql) :
--
--   1. `id_eleve` replacé en première position (permute avec `Mat_elv`,
--      simple confort de lecture — aucun impact fonctionnel, PHP accède
--      toujours aux colonnes par nom, jamais par position).
--   2. Nouvelle colonne `id_arrondissement` (FK → arrondissement.code_arrond,
--      ON DELETE SET NULL) : dès qu'on connaît l'arrondissement, département
--      et région se retrouvent par jointure — jamais stockés séparément.
--   3. Suppression de `Departement_elv` (FK → departement, désormais
--      redondante) : `arrondissement_elv` reste seul, mais change de rôle —
--      ce n'est plus la valeur "officielle" mais le repli texte libre
--      utilisé UNIQUEMENT quand id_arrondissement est NULL (lieu non
--      répertorié dans la liste officielle, ou fiche pas encore migrée).
--
--  La table `arrondissement` étant encore vide (CSV officiel pas encore
--  fourni), id_arrondissement restera NULL pour toutes les fiches
--  existantes après cette migration — leur `arrondissement_elv` (texte
--  historique) est conservé tel quel comme repli, rien n'est perdu.
--
--  À exécuter via bd/run_migration_v5.php.
-- =====================================================================

-- 1) Réordonner id_eleve en première position
ALTER TABLE `eleve`
  MODIFY COLUMN `id_eleve` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST;

-- 2) Nouvelle colonne structurée (remplace progressivement arrondissement_elv)
-- ⚠️ INT signé (pas UNSIGNED) : doit correspondre exactement au type de
-- arrondissement.code_arrond pour que la FK ci-dessous soit acceptée par
-- MySQL (erreur 3780 sinon — "incompatible" entre signé/non signé).
ALTER TABLE `eleve`
  ADD COLUMN `id_arrondissement` INT NULL AFTER `arrondissement_elv`;

ALTER TABLE `eleve`
  ADD CONSTRAINT `fk_eleve_arrondissement`
  FOREIGN KEY (`id_arrondissement`) REFERENCES `arrondissement` (`code_arrond`)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- 3) Suppression de Departement_elv (et de sa FK) — plus jamais dupliqué
ALTER TABLE `eleve` DROP FOREIGN KEY `eleve_ibfk_1`;
ALTER TABLE `eleve` DROP COLUMN `Departement_elv`;
