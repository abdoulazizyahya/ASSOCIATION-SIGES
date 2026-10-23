-- =====================================================================
--  SIGES — Migration v64 : barème arabe (Oral / Écrit / Pratique) de
--  l'année en cours pour toutes les écoles primaires
-- =====================================================================
--  Demande du 21/10/2026. Les groupes de compétences arabes, les matières
--  arabes et leur affectation aux niveaux sont déjà présents partout
--  (seed_ref_ecole.sql, migrations v46/v50). Le barème (discipline_arabe),
--  lui, n'existait que pour l'année 2025/2026 et deux classes du modèle :
--  sans barème pour l'année active, la saisie des notes arabes et les
--  bulletins arabes ne fonctionnent pas. En local seule l'école EC1 l'avait
--  saisi (onglet « Barème par niveau » de primaire/pages/matieres_arabe/
--  liste.php) : 5 / 5 / 10 sur 20 pour chaque matière.
--
--  1. Groupes, matières et matières par niveau : recopiés du modèle
--     UNIQUEMENT si la table est vide (une école qui les a personnalisés
--     n'est pas touchée).
--  2. Barème : pour l'année scolaire active, chaque classe reçoit le barème
--     5 / 5 / 10 (total 20) de chaque matière arabe active de son niveau.
--     INSERT IGNORE : un barème déjà saisi n'est jamais écrasé. Modifiable
--     ensuite par niveau dans l'onglet « Barème par niveau ».
--
--  Convention v51+ : SQL pur, autosuffisant, idempotent.
-- =====================================================================

-- 1a. Groupes de compétences arabes
INSERT INTO `groupe_matiere_arabe` (`id_groupe`, `nom_groupe_fr`, `nom_groupe_ar`)
SELECT * FROM (
    SELECT 1 AS id_groupe, 'Communiquer en langue arabe oralement et par écrit' AS fr, 'يتواصل باللغة العربية شفويا وكتابيا' AS ar
    UNION ALL SELECT 2, 'Se conformer aux principes de l''islam', 'يتبع منهج الإسلام اعتقادا وقولا وعملا'
) AS m
WHERE NOT EXISTS (SELECT 1 FROM `groupe_matiere_arabe`);

-- 1b. Matières arabes
INSERT INTO `matiere_arabe` (`id_mat`, `matiere_fr`, `matiere_ar`, `id_groupe`)
SELECT * FROM (
    SELECT 1 AS id_mat, 'Utiliser des constructions grammaticales' AS fr, 'يوظف التراكيب المناسبة' AS ar, 1 AS grp
    UNION ALL SELECT 2,  'S''exprimer oralement (ou lecture)', 'يتكلم بمهارة', 1
    UNION ALL SELECT 3,  'Copier et colorier', 'يرسم ويلون بمهارة', 1
    UNION ALL SELECT 5,  'Dialoguer avec autrui', 'يحاور غيره', 1
    UNION ALL SELECT 6,  'Avoir une foi pure et authentique et mettre en application l''unicité d''Allah', 'يوحد الله ويصحح عقيدته', 2
    UNION ALL SELECT 7,  'Étudier la biographie du prophète et le prendre comme modèle', 'يعرف سيرة النبي ويقتدي بهديه', 2
    UNION ALL SELECT 9,  'Compréhension écrite', 'يقرأ بمهارة', 1
    UNION ALL SELECT 10, 'Comprendre les règles de culte et les appliquer', 'يفقه الأحكام ويطبقها في العبادات', 2
    UNION ALL SELECT 13, 'Maîtriser la lecture, la récitation et la mise en application du Quran', 'يعتني بكتاب الله تلاوة وحفظا وعملا', 2
    UNION ALL SELECT 16, 'Évoquer, invoquer Allah et respecter les bonnes séances', 'يواظب على الأذكار والأدعية والآداب', 2
) AS m
WHERE NOT EXISTS (SELECT 1 FROM `matiere_arabe`);

-- 1c. Matières par niveau (mêmes 10 matières pour chaque niveau du modèle)
INSERT INTO `matiere_niveau_arabe` (`code_niveau`, `id_mat`, `ordre`, `actif`)
SELECT n.code_niveau, m.id_mat, m.ordre, 1
FROM (SELECT 'I' AS code_niveau UNION ALL SELECT 'II' UNION ALL SELECT 'III'
      UNION ALL SELECT 'LEVEL 1' UNION ALL SELECT 'LEVEL 2' UNION ALL SELECT 'LEVEL 3') AS n
JOIN (SELECT 1 AS id_mat, 5 AS ordre UNION ALL SELECT 2, 2 UNION ALL SELECT 3, 4 UNION ALL SELECT 5, 1
      UNION ALL SELECT 6, 2 UNION ALL SELECT 7, 5 UNION ALL SELECT 9, 3 UNION ALL SELECT 10, 4
      UNION ALL SELECT 13, 3 UNION ALL SELECT 16, 1) AS m
JOIN `matiere_arabe` ma ON ma.id_mat = m.id_mat
WHERE NOT EXISTS (SELECT 1 FROM `matiere_niveau_arabe`);

-- 2. Barème de l'année active : chaque classe × matière arabe active de son niveau
INSERT IGNORE INTO `discipline_arabe`
    (`IDClasses`, `id_mat`, `annee_scol`, `orale`, `ecrite`, `pratique`, `total_points`, `actif`)
SELECT c.IDClasses, mn.id_mat, a.val_annee, 5, 5, 10, 20, 1
FROM `classe` c
JOIN `matiere_niveau_arabe` mn ON mn.code_niveau = c.Niveau AND mn.actif = 1
JOIN `matiere_arabe` ma ON ma.id_mat = mn.id_mat
JOIN (SELECT val_annee FROM `annee_scolaire` WHERE Etat_annee_scolaire = 1 LIMIT 1) AS a;
