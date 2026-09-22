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
('4 eme',     '4ème',      '1er Cycle', '3'),
('4 eme ALL', '4 eme ALL', '1er Cycle', '3'),
('FORM 3',    'FORM 3',    '1er Cycle', '3'),
('4 eme ARA', '4 eme ARA', '1er Cycle', '4'),
('FORM 4',    'FORM 4',    '1er Cycle', '4'),
('4 eme CHI', '4 eme CHI', '1er Cycle', '5'),
('4 eme ESP', '4 eme ESP', '1er Cycle', '5'),
('FORM 5',    'FORM 5',    '1er Cycle', '5'),
('3 eme',     '3ème',      '1er Cycle', '6'),
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

-- Signataires — secondaire/pages/paiements/signatures.php (et le champ
-- « chef d'établissement » de Paramètres) font un simple UPDATE ... WHERE
-- code=? (jamais un INSERT), donc sans ces 6 lignes AUCUN upload de
-- signature ne persiste jamais : la page affiche "Signature mise à jour"
-- (flash de succès) alors que l'UPDATE touche 0 ligne, échec totalement
-- silencieux (bug réel constaté le 17/09/2026, audit étape 12). fichier
-- reste NULL (pas encore de signature) sauf pour les 2 déjà fournies par
-- LAM_ABZ de base (chef_etablissement, intendant) — copiées dans
-- assets/uploads/ à l'étape 11.
INSERT IGNORE INTO `signature_titulaire` (`code`, `libelle`, `fichier`, `role_gestion`) VALUES
('censeur', 'Censeur', NULL, 'CENSEUR'),
('chef_etablissement', 'Chef d\'établissement (Proviseur)', 'signature_chef_etablissement.png', 'PROVISEUR'),
('intendant', 'Intendant', 'signature_intendant.png', 'INTENDANT'),
('president_apee', 'Président de l\'APEE', NULL, 'INTENDANT'),
('surveillant_general', 'Surveillant Général', NULL, 'SG'),
('tresorier_apee', 'Trésorier de l\'APEE', NULL, 'INTENDANT');

-- Paie (Ressources humaines) — même contenu de départ que
-- bd/migration_v34.sql côté primaire : catégorie "Salaires" (nécessaire à
-- marquer_bulletin_paye(), paie_fonctions.php, pour enregistrer le paiement
-- d'un bulletin comme une dépense_privee) + grille salariale PLACEHOLDER
-- (montants jamais vérifiés avec l'établissement — juste un point de
-- départ, à corriger via Ressources humaines > Grille salariale).
INSERT IGNORE INTO `categorie_depense_privee` (`libelle`, `description`) VALUES
('Salaires', 'Salaires et primes du personnel');

INSERT IGNORE INTO `grade_enseignant` (`code_grade`, `libelle_grade`, `salaire_base`, `ordre_affichage`) VALUES
('VAC',   'Vacataire',              40000,  1),
('CES1',  'Professeur des CES 2e grade', 80000,  2),
('CES2',  'Professeur des CES 1er grade', 100000, 3),
('CEG',   'Professeur des CEG',     70000,  4),
('DIR',   'Direction',              150000, 5);

-- Couleurs du bulletin PDF (table pdf_couleur, comme côté primaire) — les 5
-- rôles RÉELLEMENT utilisés par secondaire/pages/bulletins/pdf.php (bulletin
-- individuel). Mêmes valeurs que les SetFillColor()/SetDrawColor() codés en
-- dur qu'ils remplacent (21/09/2026) — reprises telles quelles pour ne rien
-- changer visuellement tant que personne n'y touche depuis les réglages.
INSERT IGNORE INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES
('bandeau_titre', 'Bandeau titre (pilule d\'en-tête)', 219, 228, 245),
('bordure_marque', 'Bordure / couleur de marque', 26, 60, 107),
('entete_tableau_individuel', 'En-tête du tableau de compétences', 26, 60, 107),
('bandeau_section', 'Bandeaux de section (Disciplines/Travail/Profil/Résultats, matière, décision)', 216, 210, 248),
('ligne_echec', 'Surlignage moyenne insuffisante (< 10/20)', 255, 235, 235);
