-- =====================================================================
--  SIGES — Migration SECONDAIRE v6 : questions de sécurité
-- =====================================================================
--  Les tables question_secrete / utilisateur_question_secrete existaient
--  déjà dans schema_ref_ecole_secondaire.sql, mais question_secrete n'a
--  jamais été peuplée (fonctionnalité absente côté secondaire avant le
--  24/09/2026 — configurer_securite.php / mot_de_passe_oublie.php n'étaient
--  pas type-aware). Backfill des 10 questions par défaut (mêmes libellés
--  que côté primaire, bd/migration_v45.sql) pour les écoles secondaire déjà
--  créées. INSERT IGNORE : rejouable sans dupliquer.
-- =====================================================================

INSERT IGNORE INTO `question_secrete` (`id`, `libelle`, `actif`) VALUES
(1, 'Quel est le nom de jeune fille de votre mère ?', 1),
(2, 'Quelle est votre ville de naissance ?', 1),
(3, 'Quel est le nom de votre premier animal de compagnie ?', 1),
(4, 'Quel est le nom de votre meilleur ami d\'enfance ?', 1),
(5, 'Quel est le nom de votre école primaire ?', 1),
(6, 'Quel est votre plat préféré ?', 1),
(7, 'Quel est le prénom de votre grand-père paternel ?', 1),
(8, 'Quel est le nom de votre premier employeur ?', 1),
(9, 'Quelle est votre couleur préférée ?', 1),
(10, 'Quel surnom vous donnait-on enfant ?', 1);
