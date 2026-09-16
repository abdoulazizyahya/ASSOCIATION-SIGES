-- bd/assoc/seed_ref_ecole_secondaire.sql
-- Données de référence pédagogiques pour une école secondaire neuve
-- (schema_ref_ecole_secondaire.sql, porté de LAM_ABZ) — réplique fidèle du
-- contenu réel de la base LAM_ABZ (niveau/section_classe/groupe/serie),
-- sans quoi discipline.id_groupe (NOT NULL, FK -> groupe) et le filtrage
-- par section Fr/An du module Matières sont inutilisables. Chargé par
-- connexion_assoc.php::charger_seed_ref_ecole_secondaire(). INSERT IGNORE
-- partout : idempotent, rejouable sans dupliquer (voir aussi
-- bd/assoc/seed_ref_ecole.sql côté primaire).

INSERT IGNORE INTO `section_classe` (`libelle_section`) VALUES
('Fr'),
('An');

INSERT IGNORE INTO `niveau` (`code_niveau`, `libelle_niv`, `id_cycle`, `ordre_niveau`) VALUES
('6 eme',     '6 eme',     '1er Cycle', '1'),
('FORM 1',    'FORM 1',    '1er Cycle', '1'),
('5 eme',     '5 eme',     '1er Cycle', '2'),
('FORM 2',    'FORM 2',    '1er Cycle', '2'),
('4 eme ALL', '4 eme ALL', '1er Cycle', '3'),
('FORM 3',    'FORM 3',    '1er Cycle', '3'),
('4 eme ARA', '4 eme ARA', '1er Cycle', '4'),
('FORM 4',    'FORM 4',    '1er Cycle', '4'),
('4 eme CHI', '4 eme CHI', '1er Cycle', '5'),
('4 eme ESP', '4 eme ESP', '1er Cycle', '5'),
('FORM 5',    'FORM 5',    '1er Cycle', '5'),
('3 eme ARA', '3 eme ARA', '1er Cycle', '6'),
('3 eme CHI', '3 eme CHI', '1er Cycle', '6'),
('3 eme ESP', '3 eme ESP', '1er Cycle', '7'),
('3 eme ALL', '3 eme ALL', '1er Cycle', '8');

INSERT IGNORE INTO `groupe` (`id_groupe_comp`, `libelle_groupe_comp`, `id_section`) VALUES
(1, 'Programme Francophone', 'Fr'),
(2, 'Programme Anglophone', 'An');

INSERT IGNORE INTO `serie` (`id`, `libelle`, `id_filiere`) VALUES
(1, 'Allemand', NULL),
(2, 'Arabe', NULL),
(3, 'Bilingue', NULL),
(4, 'C', NULL),
(5, 'Chinois', NULL),
(6, 'D', NULL),
(7, 'Espagnol', NULL),
(8, 'Italien', NULL);

-- Opérateurs de paiement — 'CASH' (espèces) est un ID sentinelle en dur
-- dans secondaire/pages/paiements/save.php (paiement en espèces = pas de
-- canal électronique) ; paiement_frais.id_operateur a une FK NOT NULL vers
-- cette table, donc toute école secondaire sans cette ligne ne peut
-- enregistrer AUCUN paiement, même en espèces (bug réel constaté le
-- 16/09/2026, étape 10 — vérification module Paiements). Logos copiés dans
-- assets/uploads/operateurs/ (identiques à LAM_ABZ).
-- logo = simple nom de fichier (les pages préfixent déjà elles-mêmes
-- assets/uploads/operateurs/, ex. secondaire/pages/paiements/index.php).
INSERT IGNORE INTO `operateur_paiement` (`id`, `libelle`, `logo`) VALUES
('CASH', 'Espèces', 'cash.png'),
('OM', 'Orange Money', 'om.png'),
('MOMO', 'MTN Mobile Money', 'momo.jpg'),
('AFRILAND', 'Afriland First Bank', 'afriland.png'),
('CAMPOST', 'CAMPOST', 'campost.png'),
('ECOBANK', 'Ecobank', 'ecobank.png'),
('EU', 'Express Union', 'eu.png'),
('UBA', 'UBA', 'uba.jpg');
