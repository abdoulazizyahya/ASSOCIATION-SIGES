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

-- Questions de sécurité (configurer_securite.php / mot_de_passe_oublie.php)
-- — mêmes 10 questions que côté primaire (bd/assoc/seed_ref_ecole.sql),
-- table déjà présente dans schema_ref_ecole_secondaire.sql mais jamais
-- peuplée jusqu'ici (fonctionnalité absente côté secondaire avant le
-- 24/09/2026). IDs fixes par cohérence avec le primaire, sans incidence
-- fonctionnelle (jamais référencés en dur ailleurs).
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


-- ---------------------------------------------------------------------
-- Géographie (régions / départements / arrondissements), matières et
-- formats de carte : données qui doivent exister avant toute école, tout
-- élève, toute classe (demande du 24/09/2026 — une école créée sans elles
-- avait ces tables vides). Repris de LAM_ABZ. INSERT IGNORE : idempotent.
-- ---------------------------------------------------------------------
-- Géographie : reprise COMPLÈTE du primaire (10 régions, 58 départements,
-- 360 arrondissements) le 04/10/2026 — bd/secondaire/outils/generer_references.php.
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (1,'ADAMAOUA',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (2,'CENTRE',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (3,'EST',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (4,'EXTREME-NORD',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (5,'LITTORAL',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (6,'NORD',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (7,'NORD-OUEST',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (8,'OUEST',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (9,'SUD',NULL);
INSERT IGNORE INTO `region` (`id`, `nom`, `nom_en`) VALUES (10,'SUD-OUEST',NULL);
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1,1,'DJEREM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (2,1,'FARO-ET-DEO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (3,1,'MAYO-BANYO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (4,1,'MBERE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (5,1,'VINA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (6,2,'HAUTE-SANAGA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (7,2,'LEKIE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (8,2,'MBAM-ET-INOUBOU');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (9,2,'MBAM-ET-KIM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (10,2,'MEFOU-ET-AFAMBA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (11,2,'MEFOU-ET-AKONO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (12,2,'MFOUNDI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (13,2,'NYONG-ET-KELLE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (14,2,'NYONG-ET-MFOUMOU');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (15,2,'NYONG-ET-SO?O');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (16,3,'BOUMBA-ET-NGOKO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (17,3,'HAUT-NYONG');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (18,3,'KADEY');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (19,3,'LOM-ET-DJEREM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (20,4,'DIAMARE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (21,4,'LOGONE-ET-CHARI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (22,4,'MAYO-DANAY');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (23,4,'MAYO-KANI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (24,4,'MAYO-SAVA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (25,4,'MAYO-TSANAGA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (26,5,'MOUNGO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (27,5,'NKAM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (28,5,'SANAGA-MARITIME');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (29,5,'WOURI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (30,6,'BENOUE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (31,6,'FARO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (32,6,'MAYO-LOUTI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (33,6,'MAYO-REY');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (34,7,'BOYO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (35,7,'BUI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (36,7,'DONGA-MANTUNG');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (37,7,'MENCHUM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (38,7,'MEZAM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (39,7,'MOMO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (40,7,'NGO-KETUNJIA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (41,8,'BAMBOUTOS');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (42,8,'HAUT-NKAM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (43,8,'HAUTS-PLATEAUX');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (44,8,'KOUNG-KHI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (45,8,'MENOUA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (46,8,'MIFI');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (47,8,'NDE');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (48,8,'NOUN');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (49,9,'DJA-ET-LOBO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (50,9,'MVILA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (51,9,'OCEAN');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (52,9,'VALLEE-DU-NTEM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (53,10,'FAKO');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (54,10,'KOUPE-MANENGOUBA');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (55,10,'LEBIALEM');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (56,10,'MANYU');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (57,10,'MEME');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (58,10,'NDIAN');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (5,41,'Babadjou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (6,41,'Batcham');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (7,41,'Galim');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (8,41,'Mbouda');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (9,16,'Gari-Gombo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10,16,'Moloundou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (11,16,'Salapoumbé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (12,16,'Yokadouma');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (13,34,'Belo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (14,34,'Fundong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (15,34,'Njinikom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (16,35,'Jakiri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (17,35,'Kumbo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (18,35,'Nkum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (19,30,'Baschéo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20,30,'Bibemi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (21,30,'Dembo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (22,30,'Garoua 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (23,30,'Lagdo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (24,30,'Mayo-Hourna');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (25,30,'Pitoa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (26,30,'Touroua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (27,20,'Bogo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (28,20,'Dargala');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (29,20,'Gazawa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30,20,'Maroua 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (31,20,'Méri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (32,20,'Ndoukoula');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (33,20,'Petté');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (34,49,'Bengbis');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (35,49,'Djoum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (36,49,'Meyomessala');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (37,49,'Meyomessi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (38,49,'Mintom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (39,49,'Oveng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40,49,'Sangmelima');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (41,49,'Zoétélé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (42,1,'Ngaoundal');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (43,1,'Tibati');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (44,36,'Ako');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (45,36,'Misaje');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (46,36,'Ndu');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (47,36,'Nkambe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (48,36,'Nwa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (49,53,'Buea');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50,53,'Limbe 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (51,53,'Muyuka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (52,31,'Béka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (53,31,'Poli');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (54,2,'Galim-Tignère');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (55,2,'Kontcha');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (56,2,'Mayo-Baléo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (57,2,'Tignère');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (58,42,'Bafang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (59,42,'Bakou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60,42,'Bana');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (61,42,'Kékem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (62,17,'Abong-Mbang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (63,17,'Dimako');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (64,17,'Doumaintang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (65,17,'Doumé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (66,17,'Lomié');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (67,17,'Mboma');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (68,17,'Messaména');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (69,17,'Messok');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (70,17,'Ngoyla');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (71,17,'Nguelemendouka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (72,17,'Somalomo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (73,6,'Bibey');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (74,6,'Lembe-Yezoum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (75,6,'Mbandjock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (76,6,'Minta');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (77,6,'Nanga-Eboko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (78,6,'Nkoteng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (79,6,'Nsem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80,43,'Baham');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (81,43,'Bamendjou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (82,43,'Bangou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (83,43,'Batié');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (84,18,'Batouri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (85,18,'Kétté');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (86,18,'Mbang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (87,18,'Ndélélé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (88,44,'Bayangam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (89,54,'Bangem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90,54,'Tombel');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (91,55,'Alou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (92,55,'Wabane');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (93,7,'Batchenga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (94,7,'Ebebda');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (95,7,'Elig-Mfomo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (96,7,'Evodoula');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (97,7,'Lobo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (98,7,'Monatélé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (99,7,'Obala');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100,7,'Sa\'a');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (101,21,'Blangoua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (102,21,'Fotokol');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (103,21,'Goulfey');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (104,21,'Hile-Halifa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (105,21,'Kousseri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (106,21,'Logone-Birni');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (107,21,'Makary');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (108,21,'Waza');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (109,21,'Zina');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (110,19,'Bertoua 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (111,19,'Belabo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (112,19,'Bétaré-Oya');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (113,19,'Diang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (114,19,'Garoua-Boulaï');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (115,19,'Mandjou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (116,19,'Ngoura');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (117,56,'Akwaya');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (118,56,'Eyumodjock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (119,56,'Mamfe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (120,3,'Bankim');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (121,3,'Banyo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (122,3,'Mayo-Darlé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (123,22,'Datchéka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (124,22,'Gobo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (125,22,'Guéré');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (126,22,'Kai-Kai');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (127,22,'Kalfou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (128,22,'Kar-Hay');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (129,22,'Maga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (130,22,'Tchatibali');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (131,22,'Wina');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (132,22,'Yagoua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (133,23,'Guidiguis');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (134,23,'Kaélé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (135,23,'Mindif');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (136,23,'Moulvoudaye');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (137,23,'Moutourwa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (138,32,'Figuil');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (139,32,'Guider');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (140,32,'Mayo-Oulo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (141,33,'Madingring');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (142,33,'Tcholliré');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (143,33,'Touboro');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (144,24,'Kolofata');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (145,24,'Mora');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (146,24,'Tokombéré');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (147,25,'Bourrha');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (148,25,'Hina');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (149,25,'Koza');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (150,25,'Mogodé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (151,25,'Mokolo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (152,8,'Bafia');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (153,8,'Bokito');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (154,8,'Deuk');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (155,8,'Kiiki');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (156,8,'Kom-Yambetta');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (157,8,'Makenene');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (158,8,'Ndikinimeki');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (159,8,'Nitoukou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (160,8,'Ombessa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (161,9,'Mbangassina');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (162,9,'Ngambé-Tikar');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (163,9,'Ngoro');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (164,9,'Ntui');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (165,9,'Yoko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (166,4,'Dir');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (167,4,'Djohong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (168,4,'Meiganga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (169,4,'Ngaoui');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (170,57,'Konye');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (171,57,'Kumba 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (172,57,'Mbonge');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (173,37,'Furu-Awa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (174,37,'Wum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (175,45,'Dschang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (176,45,'Fokoué');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (177,45,'Fongo-Tongo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (178,45,'Penka-Michel');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (179,45,'Santchou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (180,38,'Bafut');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (181,38,'Bali');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (182,38,'Santa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (183,38,'Tubah');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (184,12,'Yaoundé 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (185,46,'Bafoussam 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (186,39,'Batibo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (187,39,'Mbengwi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (188,39,'Njikwa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (189,26,'Dibombari');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (190,26,'Loum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (191,26,'Manjo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (192,26,'Mbanga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (193,26,'Mélong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (194,26,'Mombo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (195,26,'Nkongsamba 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (196,50,'Biwong-Bane');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (197,50,'Biwong-Bulu');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (198,50,'Ebolowa 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (199,50,'Efoulan');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (200,50,'Mengong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (201,50,'Mvangan');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (202,50,'Ngoulemakong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (203,10,'Afanloum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (204,10,'Awaé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (205,10,'Edzendouan');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (206,10,'Esse');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (207,10,'Mfou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (208,10,'Nkolafamba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (209,10,'Soa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (210,11,'Akono');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (211,11,'Bikok');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (212,11,'Mbankomo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (213,11,'Ngoumou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (214,58,'Bamusso');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (215,58,'Dikome-Balue');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (216,58,'Ekondo Titi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (217,58,'Idabato');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (218,58,'Isangele');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (219,58,'Kombo-Abedimo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (220,58,'Kombo-Itindi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (221,58,'Mundemba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (222,58,'Toko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (223,47,'Bangangté');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (224,47,'Bassamba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (225,47,'Bazou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (226,47,'Tonga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (227,40,'Babessi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (228,40,'Balikumbat');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (229,40,'Ndop');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (230,27,'Nkondjock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (231,27,'Yabassi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (232,27,'Yingui');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (233,48,'Bangourain');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (234,48,'Foumban');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (235,48,'Kouoptamo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (236,48,'Koutaba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (237,48,'Magba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (238,48,'Malentouen');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (239,48,'Massangam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (240,48,'Njimom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (241,13,'Biyouha');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (242,13,'Bondjock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (243,13,'Bot-Makak');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (244,13,'Dibang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (245,13,'Makak');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (246,13,'Matomb');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (247,13,'Messondo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (248,13,'Ngog-Mapubi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (249,13,'Nguibassal');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (250,13,'Eséka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (251,14,'Akonolinga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (252,14,'Ayos');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (253,14,'Endom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (254,14,'Mengang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (255,15,'Akoeman');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (256,15,'Dzeng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (257,15,'Mbalmayo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (258,15,'Mengueme');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (259,15,'Ngomedzap');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (260,15,'Nkolmetet');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (261,51,'Akom II');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (262,51,'Bipindi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (263,51,'Campo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (264,51,'Kribi 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (265,51,'Lokoundje');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (266,51,'Lolodorf');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (267,51,'Mvengue');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (268,51,'Niété');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (269,28,'Dibamba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (270,28,'Dizangué');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (271,28,'Mouanko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (272,28,'Ndom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (273,28,'Ngambé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (274,28,'Ngwei');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (275,28,'Nyanon');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (276,28,'Pouma');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (277,28,'Edéa 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (278,52,'Ambam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (279,52,'Ma\'an');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (280,52,'Olamzé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (281,5,'Bélél');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (282,5,'Martap');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (283,5,'Mbé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (284,5,'Nganha');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (285,5,'Ngaoundéré 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (286,5,'Nyambaka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (287,29,'Douala 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (288,10,'Assamba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (289,14,'Nyakokombo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (290,17,'Bebend');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (291,17,'Dja');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (292,17,'Mboanz');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (293,18,'Bombé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (294,18,'Mbotoro');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (295,18,'Ndem-Nam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (296,21,'Darak');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (297,22,'Vélé');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (298,23,'Porhi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (299,23,'Taibong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (300,25,'Mayo-Moskota');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (301,25,'Soulede-Roua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (302,26,'Baré-Bakem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (303,26,'Fiko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (304,26,'Njombé-Penja');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (305,26,'Nlonako');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (306,27,'Nord-Makombe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (307,28,'Massock-Songloulou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (308,30,'Tcheboa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (309,33,'Rey-Bouba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (310,34,'Bum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (311,35,'Mbven');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (312,35,'Noni');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (313,35,'Oku');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (314,37,'Fungom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (315,37,'Menchum-Valley');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (316,38,'Bamenda 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (317,39,'Ngie');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (318,39,'Widikum-Menka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (319,44,'Djebem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (320,44,'Poumougne');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (321,45,'Nkong-Ni');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (322,52,'Kyé-Ossi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (323,53,'Tiko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (324,53,'West-Coast');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (325,54,'Nguti');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (326,55,'Fontem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (327,56,'Upper-Bayang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (328,42,'Bandja');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (329,7,'Okola');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (330,12,'Yaoundé 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (331,12,'Yaoundé 4');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (332,12,'Yaoundé 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (333,12,'Yaoundé 7');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (334,12,'Yaoundé 6');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (335,12,'Yaoundé 5');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (336,48,'Foumbot');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (337,5,'Ngaoundéré 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (338,5,'Ngaoundéré 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (339,29,'Douala 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (340,29,'Douala 4');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (341,29,'Douala 1');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (342,29,'Douala 6');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (343,29,'Douala 5');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (344,19,'Bertoua 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (345,20,'Maroua 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (346,20,'Maroua 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (347,26,'Nkongsamba 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (348,26,'Nkongsamba 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (349,28,'Edéa 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (350,30,'Demsa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (351,30,'Garoua 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (352,30,'Garoua 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (353,38,'Bamenda 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (354,38,'Bamenda 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (355,46,'Bafoussam 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (356,46,'Bafoussam 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (357,50,'Ebolowa 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (358,51,'Kribi 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (359,53,'Limbe 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (360,53,'Limbe 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (361,57,'Kumba 2');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (362,57,'Kumba 3');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (363,42,'Banka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (364,42,'Banwa');
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (1,NULL,'ANGLAIS',NULL,'Fr',1,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (2,NULL,'INFORMATIQUE',NULL,'Fr',2,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (5,NULL,'FRANÇAIS',NULL,'Fr',5,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (8,NULL,'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE',NULL,'Fr',14,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (9,NULL,'GÉOGRAPHIE',NULL,'Fr',15,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (10,NULL,'HISTOIRE',NULL,'Fr',16,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (11,NULL,'MATHÉMATIQUES',NULL,'Fr',17,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (12,NULL,'SCIENCES',NULL,'Fr',19,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (14,NULL,'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)',NULL,'Fr',21,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (15,NULL,'TRAVAIL MANUEL (TM)',NULL,'Fr',22,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (16,NULL,'FRENCH',NULL,'An',1,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (17,NULL,'COMPUTER SCIENCES',NULL,'An',2,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (19,NULL,'LITERATURE IN ENGLISH',NULL,'An',4,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (20,NULL,'ENGLISH LANGUAGE',NULL,'An',5,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (23,NULL,'CITIZENSHIP EDUCATION',NULL,'An',9,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (24,NULL,'GEOGRAPHY',NULL,'An',10,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (25,NULL,'HISTORY',NULL,'An',11,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (26,NULL,'BIOLOGY',NULL,'An',13,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (27,NULL,'CHEMISTRY',NULL,'An',15,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (29,NULL,'MATHEMATICS',NULL,'An',19,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (30,NULL,'PHYSICS',NULL,'An',20,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (31,NULL,'SPORTS AND PHYSICAL EDUCATION',NULL,'An',23,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (32,NULL,'MANUAL LABOUR',NULL,'An',24,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (33,NULL,'LVII - ALLEMAND',NULL,'Fr',13,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (34,NULL,'LVII - ARABE',NULL,'Fr',12,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (35,NULL,'LVII - ESPAGNOL',NULL,'Fr',11,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (36,NULL,'PCT',NULL,'Fr',18,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (37,NULL,'SVTEEHB',NULL,'Fr',19,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (38,NULL,'ECONOMICS',NULL,'An',12,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (39,NULL,'LOGIC',NULL,'An',12,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (40,NULL,'HUMAN BIOLOGY',NULL,'An',14,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (41,NULL,'FOOD AND NUTRITION',NULL,'An',16,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (42,NULL,'GEOLOGY',NULL,'An',17,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (43,NULL,'ADDITIONAL MATHEMATICS',NULL,'An',18,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (44,NULL,'ACCOUNTING',NULL,'An',21,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (45,NULL,'COMMERCE',NULL,'An',22,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (46,NULL,'CULTURES NATIONALES',NULL,'Fr',3,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (47,NULL,'ÉDUCATION ARTISTIQUE ET CULTURELLE',NULL,'Fr',4,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (48,NULL,'LANGUES NATIONALES',NULL,'Fr',6,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (49,NULL,'LETTRES CLASSIQUES (LATIN)',NULL,'Fr',7,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (50,NULL,'ÉCONOMIE SOCIALE ET FAMILIALE (ESF)',NULL,'Fr',20,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (51,NULL,'LETTRES CLASSIQUES (GREC)',NULL,'Fr',8,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (52,NULL,'LVII - CHINOIS',NULL,'Fr',9,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (53,NULL,'LVII - ITALIEN',NULL,'Fr',10,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (54,NULL,'ART AND CULTURE',NULL,'An',3,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (55,NULL,'NATIONAL CULTURE',NULL,'An',7,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (56,NULL,'NATIONAL LANGUAGES',NULL,'An',8,0);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (57,NULL,'HOME ECONOMICS',NULL,'An',12,1);
INSERT IGNORE INTO `format_carte` (`id`, `libelle`, `largeur_mm`, `hauteur_mm`, `cartes_par_page`) VALUES (1,'8,56 ? 5,4 cm (CB/ISO)',85.6,54.0,10);
INSERT IGNORE INTO `format_carte` (`id`, `libelle`, `largeur_mm`, `hauteur_mm`, `cartes_par_page`) VALUES (2,'12 ? 7,6 cm',120.0,76.0,6);
INSERT IGNORE INTO `format_carte` (`id`, `libelle`, `largeur_mm`, `hauteur_mm`, `cartes_par_page`) VALUES (3,'A6  (105 ? 74 mm)',105.0,74.0,4);
INSERT IGNORE INTO `format_carte` (`id`, `libelle`, `largeur_mm`, `hauteur_mm`, `cartes_par_page`) VALUES (4,'A5  (148 ? 105 mm)',148.0,105.0,2);
INSERT IGNORE INTO `format_carte` (`id`, `libelle`, `largeur_mm`, `hauteur_mm`, `cartes_par_page`) VALUES (5,'A4  pleine page',210.0,297.0,1);
