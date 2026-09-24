-- =====================================================================
--  SIGES — Migration SECONDAIRE v7 : programme par niveau (matières)
-- =====================================================================
--  Jusqu'ici, l'affectation des matières à une classe (table `discipline` :
--  matière + coefficient + groupe de compétence) était 100% manuelle,
--  classe par classe (onglet « Affectation par classe »,
--  secondaire/pages/matieres/liste.php). Demande explicite du 24/09/2026 :
--  les matières doivent être affectées AUTOMATIQUEMENT par niveau — à la
--  création d'une classe, et pour les classes existantes qui n'en ont
--  encore aucune.
--
--  Nouvelle table `programme_niveau` (voir schema_ref_ecole_secondaire.sql
--  pour sa définition) : LE programme standard par niveau. La section
--  (Fr/An) est déjà portée par matiere.libelle_section, pas de colonne
--  séparée ici — un programme couvre donc naturellement les 2 sections
--  d'un même niveau, chacune via ses propres matières.
--
--  Backfill en 2 temps, à partir des données déjà saisies :
--   1. Un programme par (niveau, matière), déduit des affectations
--      EXISTANTES (COLLEGE ISLAMIQUE DE NGAOUNDERE, où chaque niveau a déjà
--      un jeu de matières/coefficients identique sur toutes ses classes —
--      vérifié le 24/09/2026 : coef=3 Anglais partout en 6ème, etc.). MIN()
--      par groupe : en pratique une valeur unique, donc sans effet ; sert
--      de garde déterministe si jamais une incohérence existait.
--   2. Application immédiate aux classes qui n'ont ENCORE aucune matière
--      affectée (ex. COLLEGE MINHADJOUL MOUSLIM) — jamais aux classes qui
--      ont déjà des lignes `discipline` (ne touche pas à un réglage manuel
--      existant, y compris volontairement différent du programme standard).
--
--  INSERT IGNORE partout : rejouable sans dupliquer.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `programme_niveau` (
  `id`          int unsigned NOT NULL AUTO_INCREMENT,
  `code_niveau` varchar(25) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `id_matiere`  int unsigned NOT NULL,
  `id_groupe`   int NOT NULL,
  `coef`        int NOT NULL DEFAULT '1',
  `ordre`       tinyint NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_programme` (`code_niveau`,`id_matiere`),
  KEY `fk_prog_matiere` (`id_matiere`),
  KEY `fk_prog_groupe` (`id_groupe`),
  CONSTRAINT `fk_prog_matiere` FOREIGN KEY (`id_matiere`) REFERENCES `matiere` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prog_groupe` FOREIGN KEY (`id_groupe`) REFERENCES `groupe` (`id_groupe_comp`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prog_niveau` FOREIGN KEY (`code_niveau`) REFERENCES `niveau` (`code_niveau`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 1. Déduction du programme depuis les affectations déjà saisies.
INSERT IGNORE INTO `programme_niveau` (`code_niveau`, `id_matiere`, `id_groupe`, `coef`, `ordre`)
SELECT c.code_niveau, d.id_mat, MIN(d.id_groupe), MIN(d.coef), MIN(CAST(d.ordre AS UNSIGNED))
FROM `discipline` d
JOIN `classe` c ON c.id = d.IDClasses
WHERE c.code_niveau IS NOT NULL
GROUP BY c.code_niveau, d.id_mat;

-- 2. Application aux classes actives sans AUCUNE matière affectée.
INSERT IGNORE INTO `discipline` (`id_mat`, `IDClasses`, `id_groupe`, `coef`, `ordre`)
SELECT pn.id_matiere, c.id, pn.id_groupe, pn.coef, CAST(pn.ordre AS CHAR)
FROM `classe` c
JOIN `programme_niveau` pn ON pn.code_niveau = c.code_niveau
JOIN `matiere` m ON m.id = pn.id_matiere AND m.libelle_section = c.libelle_section
WHERE c.archivee = 0
  AND NOT EXISTS (SELECT 1 FROM `discipline` d2 WHERE d2.IDClasses = c.id);
