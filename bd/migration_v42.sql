-- =====================================================================
--  jaynitaare_v2 — Migration v42 : Section (Fr/An) rattachée au NIVEAU,
--  pas à la classe.
-- =====================================================================
--  Demande explicite du 20/08/2026 : la section anglophone/francophone
--  (migration_v41) est une propriété du NIVEAU, pas de la classe — quand on
--  connaît le niveau d'une classe, on connaît automatiquement sa section
--  (deux classes du même niveau ne peuvent pas être l'une francophone et
--  l'autre anglophone dans cet établissement). classe.Section (v41) est
--  donc déplacée vers niveau.Section, puis retirée de `classe`.
-- =====================================================================

-- 1. Section du niveau (même convention Fr/An que v41).
ALTER TABLE `niveau`
  ADD COLUMN `Section` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fr' AFTER `LibelleNiveau`;

-- 2. Reprise des valeurs déjà saisies sur `classe` (une par niveau — toutes
--    les classes d'un même niveau partagent la même section, donc la
--    première valeur trouvée suffit).
UPDATE `niveau` n
  JOIN `classe` c ON c.Niveau = n.LibelleNiveau
  SET n.Section = c.Section;

-- 3. Colonne classe.Section retirée — n'est plus la source de vérité.
ALTER TABLE `classe` DROP COLUMN `Section`;
