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
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (101,1,'DjÃ©rem');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (102,1,'Faro-et-DÃ©o');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (103,1,'Mayo-Banyo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (104,1,'MbÃ©rÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (105,1,'Vina');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (201,2,'Mbam-et-Inoubou');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (202,2,'Mbam-et-Kim');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (203,2,'MÃ©fou-et-Afamba');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (204,2,'MÃ©fou-et-Akono');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (205,2,'LekiÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (206,2,'Nyong-et-KÃ©llÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (207,2,'Nyong-et-Mfoumou');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (208,2,'Nyong-et-Soâo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (209,2,'Mfoundi');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (301,3,'Boumba-et-Ngoko');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (302,3,'Haut-Nyong');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (303,3,'Kadey');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (304,3,'Lom-et-DjÃ©rem');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (401,4,'DiamarÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (402,4,'Logone-et-Chari');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (403,4,'Mayo-Danay');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (404,4,'Mayo-Kani');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (405,4,'Mayo-Sava');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (406,4,'Mayo-Tsanaga');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (501,5,'Moungo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (502,5,'Nkam');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (503,5,'Sanaga-Maritime');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (504,5,'Wouri');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (601,6,'BÃ©nouÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (602,6,'Faro');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (603,6,'Mayo-Louti');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (604,6,'Mayo-Rey');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (701,7,'Boyo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (702,7,'Bui');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (703,7,'Donga-Mantung');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (704,7,'Menchum');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (705,7,'Mezam');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (706,7,'Momo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (707,7,'Ngo-Ketunjia');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (801,8,'Bamboutos');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (802,8,'Haut-Nkam');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (803,8,'Hauts-Plateaux');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (804,8,'Koung-Khi');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (805,8,'MÃ©noua');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (806,8,'Mifi');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (807,8,'NdÃ©');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (808,8,'Noun');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (901,9,'Dja-et-Lobo');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (902,9,'Mvila');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (903,9,'OcÃ©an');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (904,9,'VallÃ©e-du-Ntem');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1001,10,'Fako');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1002,10,'KoupÃ©-Manengouba');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1003,10,'Lebialem');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1004,10,'Manyu');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1005,10,'Meme');
INSERT IGNORE INTO `departement` (`id`, `id_region`, `nom`) VALUES (1006,10,'Ndian');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10101,101,'Tibati');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10102,101,'Ngaoundal');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10103,101,'Galim-TignÃ¨re');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10201,102,'TignÃ¨re');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10202,102,'Kontcha');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10301,103,'Banyo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10401,104,'Meiganga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10402,104,'Djohong');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10403,104,'Ngaoui');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10501,105,'NgaoundÃ©rÃ© Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10502,105,'NgaoundÃ©rÃ© IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10503,105,'NgaoundÃ©rÃ© IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10504,105,'Nyambaka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10505,105,'Belel');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (10506,105,'Martap');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20101,201,'Bafia');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20102,201,'NdikinimÃ©ki');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20103,201,'Kon-Yambetta');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20104,201,'Deuk');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20201,202,'Ntui');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20202,202,'Yoko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20203,202,'Mbangassina');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20204,202,'Ngoro');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20301,203,'Obala');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20302,203,'Nkolmesseng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20303,203,'Okola');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20401,204,'Ngoumou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20402,204,'Mbankomo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20403,204,'AwaÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20501,205,'MonatÃ©lÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20502,205,'Ebebda');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20503,205,'Batchenga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20601,206,'EsÃ©ka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20602,206,'Makak');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20603,206,'Bondjock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20701,207,'Akonolinga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20702,207,'Endom');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20703,207,'Ayos');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20801,208,'Mbalmayo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20802,208,'Ngomedzap');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20803,208,'Dzeng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20901,209,'YaoundÃ© Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20902,209,'YaoundÃ© IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20903,209,'YaoundÃ© IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20904,209,'YaoundÃ© IVe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20905,209,'YaoundÃ© Ve');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20906,209,'YaoundÃ© VIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (20907,209,'YaoundÃ© VIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30101,301,'Abong-Mbang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30102,301,'BÃ©tarÃ©-Oya');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30103,301,'Dimako');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30104,301,'DjouthÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30105,301,'LomiÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30106,301,'Mboma');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30107,301,'MessamÃ©na');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30108,301,'Ngoyla');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30109,301,'Mindourou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30201,302,'Batouri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30202,302,'Kentzou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30203,302,'Nguelebok');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30204,302,'Ouli');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30205,302,'Mbang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30301,303,'Abong-Mbang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30302,303,'Messok');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30303,303,'Nguelemendouka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30304,303,'Dimako');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30305,303,'DoumÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30401,304,'Bertoua Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30402,304,'Bertoua IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30403,304,'Mandjou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (30404,304,'Diang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40101,401,'Maroua Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40102,401,'Maroua IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40103,401,'Maroua IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40104,401,'Bogo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40105,401,'PettÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40106,401,'Meri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40201,402,'KoussÃ©ri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40202,402,'Makary');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40203,402,'Blangoua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40204,402,'Logone-Birni');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40301,403,'Yagoua');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40302,403,'GuÃ©rÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40303,403,'KaÃ©lÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40304,403,'Tchati-Bali');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40401,404,'KaÃ©lÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40402,404,'Moutourwa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40403,404,'Guidiguis');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40501,405,'Mora');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40502,405,'Kolofata');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (40503,405,'TokombÃ©rÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50101,501,'Nkongsamba Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50102,501,'Nkongsamba IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50103,501,'Nkongsamba IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50104,501,'Loum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50105,501,'Manjo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50106,501,'Mbanga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50107,501,'NjombÃ©-Penja');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50201,502,'Yabassi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50202,502,'Dibombari');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50203,502,'Douala-BonabÃ©ri');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50301,503,'ÃdÃ©a');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50302,503,'DizanguÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50303,503,'Pouma');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50304,503,'Ngwei');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50401,504,'Douala Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50402,504,'Douala IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50403,504,'Douala IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50404,504,'Douala IVe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50405,504,'Douala Ve');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (50406,504,'Douala VIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60101,601,'Garoua Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60102,601,'Garoua IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60103,601,'Garoua IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60104,601,'Lagdo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60105,601,'Dembo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60106,601,'BaschÃ©o');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60201,602,'Poli');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60202,602,'Gashiga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60203,602,'Beka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60301,603,'Guider');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60302,603,'Figuil');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60303,603,'Mayo-Oulo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60401,604,'TchollirÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60402,604,'Rey-Bouba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60403,604,'Madingring');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (60404,604,'Bibemi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80101,801,'Mbouda');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80102,801,'Galim');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80103,801,'Batcham');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80201,802,'Bafang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80202,802,'KÃ©kem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80203,802,'Banwa');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80301,803,'Baham');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80302,803,'Batie');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80303,803,'Bandja');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80401,804,'Bandjoun');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80402,804,'Demdeng');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80403,804,'FokouÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80501,805,'Dschang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80502,805,'Fongo-Tongo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80503,805,'Nkong-Zem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80601,806,'Bafoussam Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80602,806,'Bafoussam IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80603,806,'Bafoussam IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80701,807,'BangangtÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80702,807,'Bazou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80703,807,'Tonga');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80801,808,'Foumban');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80802,808,'Kouoptamo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80803,808,'Malentouen');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (80804,808,'Massangam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90101,901,'SangmÃ©lima');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90102,901,'Djoum');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90103,901,'ZoÃ©tÃ©lÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90104,901,'Meyomessala');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90201,902,'Ebolowa Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90202,902,'Ebolowa IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90203,902,'Bengbis');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90204,902,'Mvangan');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90301,903,'Kribi Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90302,903,'Kribi IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90303,903,'LokoundjÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90304,903,'Bipindi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90401,904,'Ambam');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90402,904,'Kye-Ossi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90403,904,'Maâan');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (90404,904,'Olamze');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100101,1001,'Buea');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100102,1001,'Limbe Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100103,1001,'Limbe IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100104,1001,'Limbe IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100105,1001,'Tiko');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100106,1001,'Muyuka');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100201,1002,'Bangem');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100202,1002,'Nguti');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100203,1002,'Tombel');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100301,1003,'Menji');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100302,1003,'Alou');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100303,1003,'Wabane');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100401,1004,'MamfÃ©');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100402,1004,'Eyumojock');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100403,1004,'Upper Bayang');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100501,1005,'Kumba Ier');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100502,1005,'Kumba IIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100503,1005,'Kumba IIIe');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100504,1005,'Konye');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100505,1005,'Mbonge');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100601,1006,'Mundemba');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100602,1006,'Isangele');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100603,1006,'Kombo-Abedimo');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100604,1006,'Kombo-Itindi');
INSERT IGNORE INTO `arrondissement` (`id`, `id_departement`, `nom`) VALUES (100605,1006,'Bamusso');
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (1,NULL,'ANGLAIS',NULL,'Fr',1,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (2,NULL,'INFORMATIQUE',NULL,'Fr',2,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (5,NULL,'FRANÇAIS',NULL,'Fr',5,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (8,NULL,'ÉDUCATION À LA CITOYENNETÉ ET À LA MORALE ',NULL,'Fr',14,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (9,NULL,'GÉOGRAPHIE',NULL,'Fr',15,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (10,NULL,'HISTOIRE',NULL,'Fr',16,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (11,NULL,'MATHÉMATIQUES',NULL,'Fr',17,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (12,NULL,'SCIENCES',NULL,'Fr',19,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (14,NULL,'ÉDUCATION PHYSIQUE ET SPORTIVE (EPS)',NULL,'Fr',21,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (15,NULL,'TRAVAIL MANUEL (TM)',NULL,'Fr',22,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (16,NULL,'FRENCH',NULL,'An',1,1);
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (17,NULL,'COMPUTER SCIENCES ',NULL,'An',2,1);
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
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (32,NULL,'MANUAL LABOUR ',NULL,'An',24,1);
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
INSERT IGNORE INTO `matiere` (`id`, `code`, `libelle`, `libelle_en`, `libelle_section`, `ordre`, `actif`) VALUES (47,NULL,'ÉDUCATION ARTISTIQUE ET CULTURELLE ',NULL,'Fr',4,0);
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
