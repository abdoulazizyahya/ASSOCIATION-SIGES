-- =====================================================================
--  SIGES — Migration SECONDAIRE v25
--  Un seul niveau 4ème / 3ème (fusion des niveaux par LV2) — 30/10/2026
-- =====================================================================
--  La référence LAM_ABZ dédoublait le niveau par LV2 : « 4 eme ALL »,
--  « 4 eme ARA », « 4 eme CHI », « 4 eme ESP » (idem 3 eme), identiques
--  sauf la matière LVII. La LV2 est désormais la SÉRIE de l'élève
--  (inscription.id_serie) et une classe peut regrouper plusieurs LV2
--  (discipline_serie, v24). Ici :
--    1. niveaux « 4 eme » / « 3 eme » (ordre 3 / 4) ;
--    2. compétences : une seule copie des matières communes + les LV2 ;
--       erreur de référence corrigée (écrit T2 d'espagnol 3ème rangé sous
--       Français) ;
--    3. notes / absences reportées sur la compétence fusionnée ;
--    4. élèves des classes « 4 eme ESP »… : série Espagnol… si vide ;
--    5. classes et frais rattachés au niveau fusionné ;
--    6. anciennes compétences puis anciens niveaux supprimés (un niveau
--       encore utilisé — frais en double — est conservé).
--  Sans effet sur une école sans ces niveaux (technique). Rejouable.
-- =====================================================================

-- 1. Niveaux fusionnés (seulement si l'école a des niveaux par LV2)
-- Réparation (02/11/2026) : bases créées avant que ces colonnes n'entrent
-- dans le schéma de référence (ex. CDA) — sautées si déjà présentes.
ALTER TABLE `classe` ADD COLUMN `id_serie` int unsigned DEFAULT NULL;
ALTER TABLE `classe` ADD KEY `id_serie` (`id_serie`);
ALTER TABLE `inscription` ADD COLUMN `id_serie` int unsigned DEFAULT NULL;
ALTER TABLE `inscription` ADD KEY `idx_insc_serie` (`id_serie`);

INSERT IGNORE INTO `niveau` (`code_niveau`, `libelle_niv`, `id_cycle`, `ordre_niveau`)
SELECT v.`code`, v.`lib`, '1er Cycle', v.`ordre`
FROM (SELECT '4 eme' AS `code`, '4ème' AS `lib`, '3' AS `ordre` UNION ALL SELECT '3 eme', '3ème', '4') v
WHERE EXISTS (SELECT 1 FROM `niveau` n WHERE n.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA'));

UPDATE `niveau` SET `ordre_niveau` = '4'
WHERE `code_niveau` = '3 eme'
  AND EXISTS (SELECT 1 FROM (SELECT `code_niveau` FROM `niveau`) n WHERE n.`code_niveau` LIKE '3 eme %');

-- 2a. Erreur de référence : écrit T2 d'espagnol 3ème rangé sous Français
UPDATE `note` nt
JOIN `competence` c ON c.`id` = nt.`id_competence` AND c.`code_niveau` = '3 eme ESP'
JOIN `matiere` mf ON mf.`id` = c.`id_matiere` AND mf.`libelle` = 'FRANÇAIS'
JOIN `matiere` me ON me.`libelle` = 'LVII - ESPAGNOL'
SET nt.`id_matiere` = me.`id`
WHERE c.`libelle` LIKE 'Comprendre et produire des messages écrits%citoyenneté%';

UPDATE `competence` c
JOIN `matiere` mf ON mf.`id` = c.`id_matiere` AND mf.`libelle` = 'FRANÇAIS'
JOIN `matiere` me ON me.`libelle` = 'LVII - ESPAGNOL'
SET c.`id_matiere` = me.`id`, c.`ordre` = 1
WHERE c.`code_niveau` = '3 eme ESP' AND c.`libelle` LIKE 'Comprendre et produire des messages écrits%citoyenneté%';

UPDATE `competence` c
JOIN `matiere` me ON me.`id` = c.`id_matiere` AND me.`libelle` = 'LVII - ESPAGNOL'
JOIN `trimestre` t ON t.`id` = c.`id_trim` AND t.`ordre` = 2
SET c.`ordre` = 2
WHERE c.`code_niveau` = '3 eme ESP' AND c.`libelle` LIKE 'Comprendre et produire des messages oraux%citoyenneté%';

-- 2b. Compétences du niveau fusionné (une copie par matière/trimestre/libellé)
INSERT INTO `competence` (`id_matiere`, `code_niveau`, `id_trim`, `libelle`, `libelle_en`, `ordre`)
SELECT c.`id_matiere`, LEFT(c.`code_niveau`, 5), c.`id_trim`, c.`libelle`, MAX(c.`libelle_en`), MIN(c.`ordre`)
FROM `competence` c
WHERE c.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `id_matiere`, `code_niveau`, `id_trim`, `libelle` FROM `competence`) b
                  WHERE b.`id_matiere` = c.`id_matiere` AND b.`code_niveau` = LEFT(c.`code_niveau`, 5)
                    AND b.`id_trim` = c.`id_trim` AND b.`libelle` = c.`libelle`)
GROUP BY c.`id_matiere`, LEFT(c.`code_niveau`, 5), c.`id_trim`, c.`libelle`;

-- 3. Notes et absences reportées sur la compétence fusionnée
UPDATE `note` nt
JOIN `competence` c ON c.`id` = nt.`id_competence`
JOIN `competence` b ON b.`id_matiere` = c.`id_matiere` AND b.`code_niveau` = LEFT(c.`code_niveau`, 5)
                   AND b.`id_trim` = c.`id_trim` AND b.`libelle` = c.`libelle`
SET nt.`id_competence` = b.`id`
WHERE c.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

UPDATE `absence_justifiee` a
JOIN `competence` c ON c.`id` = a.`id_competence`
JOIN `competence` b ON b.`id_matiere` = c.`id_matiere` AND b.`code_niveau` = LEFT(c.`code_niveau`, 5)
                   AND b.`id_trim` = c.`id_trim` AND b.`libelle` = c.`libelle`
SET a.`id_competence` = b.`id`
WHERE c.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

-- 4. Série (LV2) des élèves et des classes d'après l'ancien niveau, si vide
UPDATE `inscription` i
JOIN `classe` cl ON cl.`id` = i.`id_classe`
JOIN `serie` s ON s.`libelle` = CASE RIGHT(cl.`code_niveau`, 3) WHEN 'ALL' THEN 'Allemand' WHEN 'ARA' THEN 'Arabe'
                                    WHEN 'CHI' THEN 'Chinois' WHEN 'ESP' THEN 'Espagnol' WHEN 'ITA' THEN 'Italien' END
SET i.`id_serie` = s.`id`
WHERE i.`id_serie` IS NULL
  AND cl.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

UPDATE `classe` cl
JOIN `serie` s ON s.`libelle` = CASE RIGHT(cl.`code_niveau`, 3) WHEN 'ALL' THEN 'Allemand' WHEN 'ARA' THEN 'Arabe'
                                    WHEN 'CHI' THEN 'Chinois' WHEN 'ESP' THEN 'Espagnol' WHEN 'ITA' THEN 'Italien' END
SET cl.`id_serie` = s.`id`
WHERE cl.`id_serie` IS NULL
  AND cl.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

-- 5. Classes et frais rattachés au niveau fusionné
UPDATE `classe` SET `code_niveau` = LEFT(`code_niveau`, 5)
WHERE `code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

-- Frais : déplacés sauf s'ils existent déjà pour le niveau fusionné (sinon
-- facturés en double) — les doublons restent sur l'ancien niveau, conservé.
UPDATE IGNORE `obligation_frais` SET `code_niveau` = LEFT(`code_niveau`, 5)
WHERE `code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA');

UPDATE `obligation_privee` o
SET o.`code_niveau` = LEFT(o.`code_niveau`, 5)
WHERE o.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA')
  AND NOT EXISTS (SELECT 1 FROM (SELECT `nom_obligation`, `code_niveau` FROM `obligation_privee`) b
                  WHERE b.`code_niveau` = LEFT(o.`code_niveau`, 5) AND b.`nom_obligation` = o.`nom_obligation`);

-- 6. Anciennes compétences (leurs notes sont déjà reportées), puis anciens
--    niveaux devenus inutilisés
DELETE c FROM `competence` c
JOIN `competence` b ON b.`id_matiere` = c.`id_matiere` AND b.`code_niveau` = LEFT(c.`code_niveau`, 5)
                   AND b.`id_trim` = c.`id_trim` AND b.`libelle` = c.`libelle`
WHERE c.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA')
  AND NOT EXISTS (SELECT 1 FROM `note` nt WHERE nt.`id_competence` = c.`id`)
  AND NOT EXISTS (SELECT 1 FROM `absence_justifiee` a WHERE a.`id_competence` = c.`id`);

DELETE n FROM `niveau` n
WHERE n.`code_niveau` IN ('4 eme ALL','4 eme ARA','4 eme CHI','4 eme ESP','4 eme ITA','3 eme ALL','3 eme ARA','3 eme CHI','3 eme ESP','3 eme ITA')
  AND NOT EXISTS (SELECT 1 FROM `classe` x WHERE x.`code_niveau` = n.`code_niveau`)
  AND NOT EXISTS (SELECT 1 FROM `competence` x WHERE x.`code_niveau` = n.`code_niveau`)
  AND NOT EXISTS (SELECT 1 FROM `obligation_frais` x WHERE x.`code_niveau` = n.`code_niveau`)
  AND NOT EXISTS (SELECT 1 FROM `obligation_privee` x WHERE x.`code_niveau` = n.`code_niveau`);
