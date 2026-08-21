-- =====================================================================
--  jaynitaare_v2 — Migration v41 : section anglophone (bulletin en anglais)
-- =====================================================================
--  Demande explicite du 20/08/2026 : l'établissement est bilingue — une
--  section anglophone existe, dont les BULLETINS (pas la saisie, pas la
--  configuration des compétences, pas les statistiques) doivent s'afficher
--  en anglais avec les compétences/groupes de compétences en anglais quand
--  la classe choisie est de la section anglaise. `competence`/
--  `groupe_competence` ont déjà un jeu langue='An' (jumeau du jeu langue='Fr'
--  utilisé pour la saisie/le calcul, apparié par code_comp) — jamais
--  jusqu'ici branché sur un vrai bulletin. Référence exacte : bul_en_trim.pdf
--  fourni par l'établissement (même gabarit que le bulletin français, seuls
--  les libellés de groupe/compétence changent — le reste de la page
--  — en-tête, discipline/travail/résultats, codes, signatures — reste
--  identique, déjà bilingue FR/EN dans le modèle original).
-- =====================================================================

-- 1. Section de la classe (Fr = francophone, par défaut ; An = anglophone —
--    même convention que groupe_competence.langue/competence, apparié par
--    code_comp).
ALTER TABLE `classe`
  ADD COLUMN `Section` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Fr' AFTER `Niveau`;

-- 2. Correction des codes du jeu anglais (langue='An') pour qu'ils
--    s'apparient correctement par code_comp avec leurs jumeaux français —
--    2 bugs de données trouvés en comparant avec bul_en_trim.pdf :
--    a) Groupe "PRACTISE CITIZENSHIP VALUE" (id_groupe_comp=9) n'avait qu'UNE
--       ligne (code 3A, "Practisecitizenship value") alors que le français
--       (groupe 3) en a DEUX (3A "Pratiquer les valeurs sociales", 3B
--       "Pratiquer les valeurs Citoyennes") — la ligne anglaise existante
--       correspond en réalité au contenu du 3B français (« valeurs
--       citoyennes » = « citizenship value »), pas au 3A : recodée 3A->3B,
--       puis la ligne manquante 3A ("Practise social values", conforme au
--       modèle fourni) est insérée.
--    b) Groupe "PRACTICE PHYSICAL, SPORT AND ARTISTIC ACTIVITIES"
--       (id_groupe_comp=12) : la compétence "Practice physical and sport
--       activities" était codée 6A1, alors que son jumeau français
--       (« Pratiquer les activités physiques et sportives ») est codé 6A —
--       recodée 6A1->6A pour que l'appariement par code_comp fonctionne
--       (compétences_classe()/nom_comp_en, notes_apc.php).
UPDATE `competence` SET `code_comp` = '3B' WHERE `code_comp` = '3A' AND `id_groupe_comp` = 9;
INSERT INTO `competence` (`code_comp`, `nom_comp`, `id_groupe_comp`)
  SELECT '3A', 'Practise social values', 9
  WHERE NOT EXISTS (SELECT 1 FROM `competence` WHERE `code_comp` = '3A' AND `id_groupe_comp` = 9);
UPDATE `competence` SET `code_comp` = '6A' WHERE `code_comp` = '6A1' AND `id_groupe_comp` = 12;
