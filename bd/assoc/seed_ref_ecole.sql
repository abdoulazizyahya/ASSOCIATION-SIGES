-- =====================================================================
--  bd/assoc/seed_ref_ecole.sql
--  Donnees de REFERENCE communes a toutes les ecoles (ne changent pas d'une
--  ecole a l'autre) : niveaux, groupes de competences / competences (Fr+An),
--  affectation des groupes aux niveaux, disciplines / matieres arabes,
--  criteres de conseil, geographie (pays/region/departement/arrondissement),
--  grades enseignants, questions secretes, couleurs PDF, categories de depense.
--
--  Charge automatiquement par charger_schema_ecole() (connexion_assoc.php)
--  a la creation d'un etablissement, au vidage et a la creation de base.
--  Re-applicable a une ecole existante : bd/assoc/reseeder_ecole.php <CODE>.
--
--  Genere depuis la base EC1 (promeducam_jaynitaare) le 2026-09-07.
--  Chaque bloc : DELETE puis INSERT — idempotent.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;


-- ---- niveau ----
DELETE FROM `niveau`;
INSERT INTO `niveau` (`LibelleNiveau`, `Section`, `OrdreNiveau`, `actif`) VALUES ('I','Fr',2,1),('M','Fr',1,1),('II','Fr',3,1),('III','Fr',4,1),('LEVEL 1','An',5,1),('LEVEL 2','An',6,1),('LEVEL 3','An',7,1);

-- ---- groupe_competence ----
DELETE FROM `groupe_competence`;
INSERT INTO `groupe_competence` (`id_groupe_comp`, `libelle_groupe_comp`, `langue`, `ordre_affichage`) VALUES (1,'COMMUNIQUER EN FRANÇAIS','Fr',1),(2,'UTILISER LES NOTIONS DE BASE EN MATHÉMATIQUES, SCIENCES ET TECHNOLOGIES ','Fr',2),(3,'PRATIQUER LES VALEURS SOCIALES ET CITOYENNES','Fr',3),(4,'DÉMONTRER L\'AUTONOMIE, L\'ESPRIT D\'INITIATIVE, DE CRÉATIVITÉ ET D\'ENTREPRENEURIAT ','Fr',4),(5,'UTILISER LES CONCEPTS DE BASE ET LES OUTILS DE TECHNOLOGIES DE L\'INFORMATION ET DE LA COMMUNICATION ','Fr',5),(6,'PRATIQUER LES ACTIVITÉS PHYSIQUES, SPORTIVES ET ARTISTIQUES ','Fr',6),(7,'COMMUNICATE IN ENGLICH,FRENCH AND ONE NATIONAL LANGUAGE','An',1),(8,'USE BASIC NOTIONS IN MATHÉMATICS , SCIENCE AND TECHNOLOGY ','An',2),(9,'PRACTISE CITIZENSHIP VALUE','An',3),(10,'DEMONSTRATE AUTONOMY, SPIRIT OF INITIATIVE CREATIVITY AND ENTREPRENEURSHIP IN VOCATIONAL STUDIES','An',4),(11,'USE BASIC CONCEPT AND TOOLS OF INFORMATION AND COMMUNICATION TECHNOLOGY','An',5),(12,'PRACTICE PHYSICAL ,  SPORT AND ARTISTIC  ACTIVITIES','An',6);

-- ---- competence ----
DELETE FROM `competence`;
INSERT INTO `competence` (`id_comp`, `code_comp`, `nom_comp`, `id_groupe_comp`) VALUES (1,'1A','Communiquer en Français',1),(2,'1B','Communicate in English',1),(3,'1C','Pratiquer une langue Nationale',1),(4,'2A','Utiliser les notions de base en mathématiques',2),(5,'2B','Utiliser les notions de base en Sciences et Techno.',2),(6,'3A','Pratiquer les valeurs sociales',3),(7,'3B','Pratiquer les valeurs Citoyennes',3),(8,'4A','Démontrer l\'autonomie,l\'esprit d\'initiative... ',4),(9,'5A','Utiliser les concepts de base et les outils des TIC',5),(10,'6A','Pratiquer les activités physiques et sportives',6),(11,'6B','Pratiquer les activités artistiques',6),(12,'6C1','Utiliser les concepts de base en Arabe',6),(13,'6C2','Pratiquer l\'éducation Islamique',6),(14,'1A','Communicate in English ',7),(15,'1B','Communicate  in French',7),(16,'1C','Communicate in one national language',7),(17,'2A','Use basic notions in mathematics',8),(18,'2B','Use basic notions in science and technology',8),(19,'3B','Practisecitizenship value',9),(20,'4A','Demonstrate autonomy,spirit of init.iative,creat.',10),(21,'5A','Use basic concept and tools of TIC',11),(22,'6A','Practice physical and sport activities',12),(23,'6B','Practice artistic activities ',12),(24,'6C1','Arabe',12),(25,'6C2','Islamic Education',12),(27,'3A','Practise social values',9);

-- ---- groupe_competence_niveau ----
DELETE FROM `groupe_competence_niveau`;
INSERT INTO `groupe_competence_niveau` (`code_niveau`, `id_groupe_comp`, `actif`) VALUES ('I',1,1),('I',2,1),('I',3,1),('I',4,1),('I',5,1),('I',6,1),('II',1,1),('II',2,1),('II',3,1),('II',4,1),('II',5,1),('II',6,1),('III',1,1),('III',2,1),('III',3,1),('III',4,1),('III',5,1),('III',6,1),('LEVEL 1',7,1),('LEVEL 1',8,1),('LEVEL 1',9,1),('LEVEL 1',10,1),('LEVEL 1',11,1),('LEVEL 1',12,1),('LEVEL 2',7,1),('LEVEL 2',8,1),('LEVEL 2',9,1),('LEVEL 2',10,1),('LEVEL 2',11,1),('LEVEL 2',12,1),('LEVEL 3',7,1),('LEVEL 3',8,1),('LEVEL 3',9,1),('LEVEL 3',10,1),('LEVEL 3',11,1),('LEVEL 3',12,1);

-- ---- critere_conseil ----
DELETE FROM `critere_conseil`;
INSERT INTO `critere_conseil` (`id_classe`, `val_annee`, `moyenne_admission`, `moyenne_exclusion`, `jours_absence_max`, `jours_exclusion_max`, `seuil_tableau_honneur`, `seuil_encouragement`, `seuil_felicitation`) VALUES (1,'2025/2026',10.00,NULL,NULL,NULL,NULL,NULL,NULL),(4,'2025/2026',NULL,NULL,NULL,NULL,12.00,14.00,15.00);

-- ---- critere_conseil_arabe ----
DELETE FROM `critere_conseil_arabe`;

-- ---- discipline_arabe ----
DELETE FROM `discipline_arabe`;
INSERT INTO `discipline_arabe` (`IDClasses`, `id_mat`, `annee_scol`, `orale`, `ecrite`, `pratique`, `total_points`, `actif`) VALUES (3,1,'2025/2026',7,7,6,20,1),(3,2,'2025/2026',7,7,6,20,1),(3,3,'2025/2026',7,7,6,20,1),(3,5,'2025/2026',7,7,6,20,1),(3,6,'2025/2026',7,7,6,20,1),(3,7,'2025/2026',7,7,6,20,1),(4,1,'2025/2026',7,7,6,20,1),(4,2,'2025/2026',7,7,6,20,1),(4,3,'2025/2026',7,7,6,20,1),(4,5,'2025/2026',7,7,6,20,1),(4,6,'2025/2026',7,7,6,20,1),(4,7,'2025/2026',7,7,6,20,1);

-- ---- groupe_matiere_arabe ----
DELETE FROM `groupe_matiere_arabe`;
INSERT INTO `groupe_matiere_arabe` (`id_groupe`, `nom_groupe_fr`, `nom_groupe_ar`) VALUES (1,'Communiquer en langue arabe oralement et par écrit','يتواصل باللغة العربية شفويا وكتابيا'),(2,'Se conformer aux principes de l\'islam','يتبع منهج الإسلام اعتقادا وقولا وعملا');

-- ---- matiere_arabe ----
DELETE FROM `matiere_arabe`;
INSERT INTO `matiere_arabe` (`id_mat`, `matiere_fr`, `matiere_ar`, `id_groupe`) VALUES (1,'Utiliser des constructions grammaticales','يوظف التراكيب المناسبة',1),(2,'S\'exprimer oralement (ou lecture)','يتكلم بمهارة',1),(3,'Copier et colorier','يرسم ويلون بمهارة',1),(5,'Dialoguer avec autrui','يحاور غيره',1),(6,'Avoir une foi pure et authentique et mettre en application l\'unicité d\'Allah','يوحد الله ويصحح عقيدته',2),(7,'Étudier la biographie du prophète et le prendre comme modèle','يعرف سيرة النبي ويقتدي بهديه',2),(9,'Compréhension écrite','يقرأ بمهارة',1),(10,'Comprendre les règles de culte et les appliquer','يفقه الأحكام ويطبقها في العبادات',2),(13,'Maîtriser la lecture, la récitation et la mise en application du Quran','يعتني بكتاب الله تلاوة وحفظا وعملا',2),(16,'Évoquer, invoquer Allah et respecter les bonnes séances','يواظب على الأذكار والأدعية والآداب',2);

-- ---- matiere_niveau_arabe ----
DELETE FROM `matiere_niveau_arabe`;
INSERT INTO `matiere_niveau_arabe` (`code_niveau`, `id_mat`, `ordre`, `actif`) VALUES ('I',1,5,1),('I',2,2,1),('I',3,4,1),('I',5,1,1),('I',6,2,1),('I',7,5,1),('I',9,3,1),('I',10,4,1),('I',13,3,1),('I',16,1,1),('II',1,5,1),('II',2,2,1),('II',3,4,1),('II',5,1,1),('II',6,2,1),('II',7,5,1),('II',9,3,1),('II',10,4,1),('II',13,3,1),('II',16,1,1),('III',1,5,1),('III',2,2,1),('III',3,4,1),('III',5,1,1),('III',6,2,1),('III',7,5,1),('III',9,3,1),('III',10,4,1),('III',13,3,1),('III',16,1,1),('LEVEL 1',1,5,1),('LEVEL 1',2,2,1),('LEVEL 1',3,4,1),('LEVEL 1',5,1,1),('LEVEL 1',6,2,1),('LEVEL 1',7,5,1),('LEVEL 1',9,3,1),('LEVEL 1',10,4,1),('LEVEL 1',13,3,1),('LEVEL 1',16,1,1),('LEVEL 2',1,5,1),('LEVEL 2',2,2,1),('LEVEL 2',3,4,1),('LEVEL 2',5,1,1),('LEVEL 2',6,2,1),('LEVEL 2',7,4,1),('LEVEL 2',9,3,1),('LEVEL 2',10,4,1),('LEVEL 2',13,3,1),('LEVEL 2',16,1,1),('LEVEL 3',1,5,1),('LEVEL 3',2,2,1),('LEVEL 3',3,4,1),('LEVEL 3',5,1,1),('LEVEL 3',6,2,1),('LEVEL 3',7,5,1),('LEVEL 3',9,3,1),('LEVEL 3',10,4,1),('LEVEL 3',13,3,1),('LEVEL 3',16,1,1);

-- ---- pays ----
DELETE FROM `pays`;
INSERT INTO `pays` (`code_pays`, `nom_pays`, `nationalite`) VALUES (1,'CAMEROUN','CAMEROUNAISE'),(2,'TCHAD','TCHADIENNE'),(3,'CENTRAFRIQUE','CENTRAFRICAINE'),(4,'GABON','GABONAISE');

-- ---- region ----
DELETE FROM `region`;
INSERT INTO `region` (`id_region`, `intitule_region`, `code_pays`) VALUES (1,'ADAMAOUA',1),(2,'CENTRE',1),(3,'EST',1),(4,'EXTREME-NORD',1),(5,'LITTORAL',1),(6,'NORD',1),(7,'NORD-OUEST',1),(8,'OUEST',1),(9,'SUD',1),(10,'SUD-OUEST',1);

-- ---- departement ----
DELETE FROM `departement`;
INSERT INTO `departement` (`code_depart`, `intitule_depart`, `chef_lieu`, `code_region`) VALUES (1,'DJEREM','TIBATI',1),(2,'FARO-ET-DEO','TIGNERE',1),(3,'MAYO-BANYO','BANYO',1),(4,'MBERE','MEIGANGA',1),(5,'VINA','NGAOUNDERE',1),(6,'HAUTE-SANAGA','NANGA-EBOKO',2),(7,'LEKIE','MONATELE',2),(8,'MBAM-ET-INOUBOU','BAFIA',2),(9,'MBAM-ET-KIM','NTUI',2),(10,'MEFOU-ET-AFAMBA','MFOU',2),(11,'MEFOU-ET-AKONO','NGOUMOU',2),(12,'MFOUNDI','YAOUNDE',2),(13,'NYONG-ET-KELLE','ESEKA',2),(14,'NYONG-ET-MFOUMOU','AKONOLINGA',2),(15,'NYONG-ET-SO?O','MBALMAYO',2),(16,'BOUMBA-ET-NGOKO','YOKADOUMA',3),(17,'HAUT-NYONG','ABONG-MBANG',3),(18,'KADEY','BATOURI',3),(19,'LOM-ET-DJEREM','BERTOUA',3),(20,'DIAMARE','MAROUA',4),(21,'LOGONE-ET-CHARI','KOUSSERI',4),(22,'MAYO-DANAY','YAGOUA',4),(23,'MAYO-KANI','KAELE',4),(24,'MAYO-SAVA','MORA',4),(25,'MAYO-TSANAGA','MOKOLO',4),(26,'MOUNGO','NKONGSAMBA',5),(27,'NKAM','YABASSI',5),(28,'SANAGA-MARITIME','EDEA',5),(29,'WOURI','DOUALA',5),(30,'BENOUE','GAROUA',6),(31,'FARO','POLI',6),(32,'MAYO-LOUTI','GUIDER',6),(33,'MAYO-REY','TCHOLLIRE',6),(34,'BOYO','FUNDONG',7),(35,'BUI','KUMBO',7),(36,'DONGA-MANTUNG','NKAMBE',7),(37,'MENCHUM','WUM',7),(38,'MEZAM','BAMENDA',7),(39,'MOMO','MBENGWI',7),(40,'NGO-KETUNJIA','NDOP',7),(41,'BAMBOUTOS','MBOUDA',8),(42,'HAUT-NKAM','BAFANG',8),(43,'HAUTS-PLATEAUX','BAHAM',8),(44,'KOUNG-KHI','BANDJOUN',8),(45,'MENOUA','DSCHANG',8),(46,'MIFI','BAFOUSSAM',8),(47,'NDE','BANGANGTE',8),(48,'NOUN','FOUMBAN',8),(49,'DJA-ET-LOBO','SANGMELIMA',9),(50,'MVILA','EBOLOWA',9),(51,'OCEAN','KRIBI',9),(52,'VALLEE-DU-NTEM','AMBAM',9),(53,'FAKO','LIMBE',10),(54,'KOUPE-MANENGOUBA','BANGEM',10),(55,'LEBIALEM','MENJI',10),(56,'MANYU','MAMFE',10),(57,'MEME','KUMBA',10),(58,'NDIAN','MUNDEMBA',10);

-- ---- arrondissement ----
DELETE FROM `arrondissement`;
INSERT INTO `arrondissement` (`code_arrond`, `intitule_arrond`, `code_depart`) VALUES (42,'Ngaoundal',1),(43,'Tibati',1),(54,'Galim-Tignère',2),(55,'Kontcha',2),(56,'Mayo-Baléo',2),(57,'Tignère',2),(120,'Bankim',3),(121,'Banyo',3),(122,'Mayo-Darlé',3),(166,'Dir',4),(167,'Djohong',4),(168,'Meiganga',4),(169,'Ngaoui',4),(281,'Bélél',5),(282,'Martap',5),(283,'Mbé',5),(284,'Nganha',5),(338,'Ngaoundéré 1',5),(337,'Ngaoundéré 2',5),(285,'Ngaoundéré 3',5),(286,'Nyambaka',5),(73,'Bibey',6),(74,'Lembe-Yezoum',6),(75,'Mbandjock',6),(76,'Minta',6),(77,'Nanga-Eboko',6),(78,'Nkoteng',6),(79,'Nsem',6),(93,'Batchenga',7),(94,'Ebebda',7),(95,'Elig-Mfomo',7),(96,'Evodoula',7),(97,'Lobo',7),(98,'Monatélé',7),(99,'Obala',7),(329,'Okola',7),(100,'Sa\'a',7),(152,'Bafia',8),(153,'Bokito',8),(154,'Deuk',8),(155,'Kiiki',8),(156,'Kom-Yambetta',8),(157,'Makenene',8),(158,'Ndikinimeki',8),(159,'Nitoukou',8),(160,'Ombessa',8),(161,'Mbangassina',9),(162,'Ngambé-Tikar',9),(163,'Ngoro',9),(164,'Ntui',9),(165,'Yoko',9),(203,'Afanloum',10),(288,'Assamba',10),(204,'Awaé',10),(205,'Edzendouan',10),(206,'Esse',10),(207,'Mfou',10),(208,'Nkolafamba',10),(209,'Soa',10),(210,'Akono',11),(211,'Bikok',11),(212,'Mbankomo',11),(213,'Ngoumou',11),(332,'Yaoundé 1',12),(330,'Yaoundé 2',12),(184,'Yaoundé 3',12),(331,'Yaoundé 4',12),(335,'Yaoundé 5',12),(334,'Yaoundé 6',12),(333,'Yaoundé 7',12),(241,'Biyouha',13),(242,'Bondjock',13),(243,'Bot-Makak',13),(244,'Dibang',13),(250,'Eséka',13),(245,'Makak',13),(246,'Matomb',13),(247,'Messondo',13),(248,'Ngog-Mapubi',13),(249,'Nguibassal',13),(251,'Akonolinga',14),(252,'Ayos',14),(253,'Endom',14),(254,'Mengang',14),(289,'Nyakokombo',14),(255,'Akoeman',15),(256,'Dzeng',15),(257,'Mbalmayo',15),(258,'Mengueme',15),(259,'Ngomedzap',15),(260,'Nkolmetet',15),(9,'Gari-Gombo',16),(10,'Moloundou',16),(11,'Salapoumbé',16),(12,'Yokadouma',16),(62,'Abong-Mbang',17),(290,'Bebend',17),(63,'Dimako',17),(291,'Dja',17),(64,'Doumaintang',17),(65,'Doumé',17),(66,'Lomié',17),(292,'Mboanz',17),(67,'Mboma',17),(68,'Messaména',17),(69,'Messok',17),(70,'Ngoyla',17),(71,'Nguelemendouka',17),(72,'Somalomo',17),(84,'Batouri',18),(293,'Bombé',18),(85,'Kétté',18),(86,'Mbang',18),(294,'Mbotoro',18),(87,'Ndélélé',18),(295,'Ndem-Nam',18),(111,'Belabo',19),(110,'Bertoua 1',19),(344,'Bertoua 2',19),(112,'Bétaré-Oya',19),(113,'Diang',19),(114,'Garoua-Boulaï',19),(115,'Mandjou',19),(116,'Ngoura',19),(27,'Bogo',20),(28,'Dargala',20),(29,'Gazawa',20),(30,'Maroua 1',20),(345,'Maroua 2',20),(346,'Maroua 3',20),(31,'Méri',20),(32,'Ndoukoula',20),(33,'Petté',20),(101,'Blangoua',21),(296,'Darak',21),(102,'Fotokol',21),(103,'Goulfey',21),(104,'Hile-Halifa',21),(105,'Kousseri',21),(106,'Logone-Birni',21),(107,'Makary',21),(108,'Waza',21),(109,'Zina',21),(123,'Datchéka',22),(124,'Gobo',22),(125,'Guéré',22),(126,'Kai-Kai',22),(127,'Kalfou',22),(128,'Kar-Hay',22),(129,'Maga',22),(130,'Tchatibali',22),(297,'Vélé',22),(131,'Wina',22),(132,'Yagoua',22),(133,'Guidiguis',23),(134,'Kaélé',23),(135,'Mindif',23),(136,'Moulvoudaye',23),(137,'Moutourwa',23),(298,'Porhi',23),(299,'Taibong',23),(144,'Kolofata',24),(145,'Mora',24),(146,'Tokombéré',24),(147,'Bourrha',25),(148,'Hina',25),(149,'Koza',25),(300,'Mayo-Moskota',25),(150,'Mogodé',25),(151,'Mokolo',25),(301,'Soulede-Roua',25),(302,'Baré-Bakem',26),(189,'Dibombari',26),(303,'Fiko',26),(190,'Loum',26),(191,'Manjo',26),(192,'Mbanga',26),(193,'Mélong',26),(194,'Mombo',26),(304,'Njombé-Penja',26),(195,'Nkongsamba 1',26),(347,'Nkongsamba 2',26),(348,'Nkongsamba 3',26),(305,'Nlonako',26),(230,'Nkondjock',27),(306,'Nord-Makombe',27),(231,'Yabassi',27),(232,'Yingui',27),(269,'Dibamba',28),(270,'Dizangué',28),(277,'Edéa 1',28),(349,'Edéa 2',28),(307,'Massock-Songloulou',28),(271,'Mouanko',28),(272,'Ndom',28),(273,'Ngambé',28),(274,'Ngwei',28),(275,'Nyanon',28),(276,'Pouma',28),(341,'Douala 1',29),(339,'Douala 2',29),(287,'Douala 3',29),(340,'Douala 4',29),(343,'Douala 5',29),(342,'Douala 6',29),(19,'Baschéo',30),(20,'Bibemi',30),(21,'Dembo',30),(350,'Demsa',30),(22,'Garoua 1',30),(351,'Garoua 2',30),(352,'Garoua 3',30),(23,'Lagdo',30),(24,'Mayo-Hourna',30),(25,'Pitoa',30),(308,'Tcheboa',30),(26,'Touroua',30),(52,'Béka',31),(53,'Poli',31),(138,'Figuil',32),(139,'Guider',32),(140,'Mayo-Oulo',32),(141,'Madingring',33),(309,'Rey-Bouba',33),(142,'Tcholliré',33),(143,'Touboro',33),(13,'Belo',34),(310,'Bum',34),(14,'Fundong',34),(15,'Njinikom',34),(16,'Jakiri',35),(17,'Kumbo',35),(311,'Mbven',35),(18,'Nkum',35),(312,'Noni',35),(313,'Oku',35),(44,'Ako',36),(45,'Misaje',36),(46,'Ndu',36),(47,'Nkambe',36),(48,'Nwa',36),(314,'Fungom',37),(173,'Furu-Awa',37),(315,'Menchum-Valley',37),(174,'Wum',37),(180,'Bafut',38),(181,'Bali',38),(316,'Bamenda 1',38),(353,'Bamenda 2',38),(354,'Bamenda 3',38),(182,'Santa',38),(183,'Tubah',38),(186,'Batibo',39),(187,'Mbengwi',39),(317,'Ngie',39),(188,'Njikwa',39),(318,'Widikum-Menka',39),(227,'Babessi',40),(228,'Balikumbat',40),(229,'Ndop',40),(5,'Babadjou',41),(6,'Batcham',41),(7,'Galim',41),(8,'Mbouda',41),(58,'Bafang',42),(59,'Bakou',42),(60,'Bana',42),(328,'Bandja',42),(363,'Banka',42),(364,'Banwa',42),(61,'Kékem',42),(80,'Baham',43),(81,'Bamendjou',43),(82,'Bangou',43),(83,'Batié',43),(88,'Bayangam',44),(319,'Djebem',44),(320,'Poumougne',44),(175,'Dschang',45),(176,'Fokoué',45),(177,'Fongo-Tongo',45),(321,'Nkong-Ni',45),(178,'Penka-Michel',45),(179,'Santchou',45),(185,'Bafoussam 1',46),(355,'Bafoussam 2',46),(356,'Bafoussam 3',46),(223,'Bangangté',47),(224,'Bassamba',47),(225,'Bazou',47),(226,'Tonga',47),(233,'Bangourain',48),(234,'Foumban',48),(336,'Foumbot',48),(235,'Kouoptamo',48),(236,'Koutaba',48),(237,'Magba',48),(238,'Malentouen',48),(239,'Massangam',48),(240,'Njimom',48),(34,'Bengbis',49),(35,'Djoum',49),(36,'Meyomessala',49),(37,'Meyomessi',49),(38,'Mintom',49),(39,'Oveng',49),(40,'Sangmelima',49),(41,'Zoétélé',49),(196,'Biwong-Bane',50),(197,'Biwong-Bulu',50),(198,'Ebolowa 1',50),(357,'Ebolowa 2',50),(199,'Efoulan',50),(200,'Mengong',50),(201,'Mvangan',50),(202,'Ngoulemakong',50),(261,'Akom II',51),(262,'Bipindi',51),(263,'Campo',51),(264,'Kribi 1',51),(358,'Kribi 2',51),(265,'Lokoundje',51),(266,'Lolodorf',51),(267,'Mvengue',51),(268,'Niété',51),(278,'Ambam',52),(322,'Kyé-Ossi',52),(279,'Ma\'an',52),(280,'Olamzé',52),(49,'Buea',53),(50,'Limbe 1',53),(359,'Limbe 2',53),(360,'Limbe 3',53),(51,'Muyuka',53),(323,'Tiko',53),(324,'West-Coast',53),(89,'Bangem',54),(325,'Nguti',54),(90,'Tombel',54),(91,'Alou',55),(326,'Fontem',55),(92,'Wabane',55),(117,'Akwaya',56),(118,'Eyumodjock',56),(119,'Mamfe',56),(327,'Upper-Bayang',56),(170,'Konye',57),(171,'Kumba 1',57),(361,'Kumba 2',57),(362,'Kumba 3',57),(172,'Mbonge',57),(214,'Bamusso',58),(215,'Dikome-Balue',58),(216,'Ekondo Titi',58),(217,'Idabato',58),(218,'Isangele',58),(219,'Kombo-Abedimo',58),(220,'Kombo-Itindi',58),(221,'Mundemba',58),(222,'Toko',58);

-- ---- fonction ----
DELETE FROM `fonction`;
INSERT INTO `fonction` (`id_fonction`) VALUES ('COMPTABLE'),('DIRECTEUR'),('ENSEIGNANT'),('FONDATEUR'),('SECRETAIRE');

-- ---- grade_enseignant ----
DELETE FROM `grade_enseignant`;
INSERT INTO `grade_enseignant` (`code_grade`, `libelle_grade`, `salaire_base`, `ordre_affichage`) VALUES ('DIR','Direction',150000.00,5),('INST1','Instituteur adjoint',60000.00,2),('INST2','Instituteur',80000.00,3),('PROF','Professeur des écoles',100000.00,4),('VAC','Vacataire',40000.00,1);

-- ---- indemnite_grade ----
DELETE FROM `indemnite_grade`;
INSERT INTO `indemnite_grade` (`id`, `code_grade`, `libelle_indemnite`, `montant`) VALUES (2,'VAC','Transport',5000.00),(3,'INST2','Transport',5000.00),(4,'DIR','Transport',10000.00),(5,'DIR','Telephone',5000.00),(6,'INST2','Transport',5000.00);

-- ---- question_secrete ----
DELETE FROM `question_secrete`;
INSERT INTO `question_secrete` (`id`, `libelle`, `actif`) VALUES (1,'Quel est le nom de jeune fille de votre mère ?',1),(2,'Quelle est votre ville de naissance ?',1),(3,'Quel est le nom de votre premier animal de compagnie ?',1),(4,'Quel est le nom de votre meilleur ami d\'enfance ?',1),(5,'Quel est le nom de votre école primaire ?',1),(6,'Quel est votre plat préféré ?',1),(7,'Quel est le prénom de votre grand-père paternel ?',1),(8,'Quel est le nom de votre premier employeur ?',1),(9,'Quelle est votre couleur préférée ?',1),(10,'Quel surnom vous donnait-on enfant ?',1);

-- ---- pdf_couleur ----
DELETE FROM `pdf_couleur`;
INSERT INTO `pdf_couleur` (`cle`, `libelle`, `r`, `g`, `b`) VALUES ('cellule_resultat','Cellules de résultat (Moyenne/Rang/Appréciation)',228,228,228),('colonne_annuelle','Colonne ANNUELLE (bulletin annuel arabe)',250,214,165),('entete_bleu','En-têtes (bulletin annuel + bulletins arabes)',146,220,255),('entete_section','En-têtes de section (Discipline/Travail/Profil, FR trim.)',65,165,165),('entete_tableau_arabe','En-tête du tableau de compétences (bulletin trim. arabe)',0,153,153),('groupe_competence','En-tête des groupes de compétences',45,231,218),('groupe_tableau_arabe','Bandeaux de groupe du tableau de compétences (bulletin trim. arabe)',252,213,180),('ligne_alternee','Lignes alternées (tableau de notes)',227,227,227),('ligne_rayee','Rayures de lignes (bulletin trimestriel FR)',249,249,249),('totaux_tableau_arabe','Ligne TOTAUX du tableau de compétences (bulletin trim. arabe)',197,217,241);

-- ---- categorie_depense ----
DELETE FROM `categorie_depense`;
INSERT INTO `categorie_depense` (`id_categorie`, `libelle`, `description`) VALUES (1,'Salaires','Salaires et primes du personnel'),(2,'Fournitures scolaires','Matériel et fournitures pédagogiques'),(3,'Entretien & réparations','Maintenance des locaux et équipements'),(4,'Eau & Électricité','Factures d\'eau et d\'électricité'),(5,'Transport','Frais de transport et carburant'),(6,'Administration','Frais administratifs divers'),(7,'Autres','Dépenses non classées ailleurs');

-- ---- bareme_reference (bareme APC standard par niveau + competence) ----
--  Distribution de points (oral/ecrit/pratique/savoir-etre) par competence
--  et par niveau, appliquee automatiquement a chaque classe creee et a la
--  1re annee scolaire d'une ecole neuve (voir appliquer_bareme_reference()).
--  N'est PAS le bareme de travail : celui-ci reste dans `discipline`
--  (par classe et par annee). Ceci n'est que le gabarit de depart.
CREATE TABLE IF NOT EXISTS `bareme_reference` (
  `code_niveau`   varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_comp`       int NOT NULL,
  `orale`         float DEFAULT 0,
  `ecrite`        float DEFAULT 0,
  `pratique`      float DEFAULT 0,
  `savoir_etre`   float DEFAULT 0,
  `total_points`  float DEFAULT 0,
  `actif`         tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`code_niveau`,`id_comp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DELETE FROM `bareme_reference`;
INSERT INTO `bareme_reference` (`code_niveau`,`id_comp`,`orale`,`ecrite`,`pratique`,`savoir_etre`,`total_points`,`actif`) VALUES
('I',1,20,15,0,5,40,1),('I',2,20,15,0,5,40,1),('I',3,10,5,3,2,20,1),('I',4,5,20,0,5,30,1),('I',5,5,5,15,5,30,1),('I',6,3,3,10,4,20,1),('I',7,5,5,8,2,20,1),('I',8,5,3,10,2,20,1),('I',9,3,3,10,4,20,1),('I',10,3,3,10,4,20,1),('I',11,4,3,10,3,20,1),('II',1,12,15,0,3,30,1),('II',2,12,15,0,3,30,1),('II',3,10,6,2,2,20,1),('II',4,8,28,0,4,40,1),('II',5,6,7,20,7,40,1),('II',6,3,8,5,4,20,1),('II',7,3,9,5,3,20,1),('II',8,5,2,11,2,20,1),('II',9,4,10,20,6,40,1),('II',10,2,2,12,4,20,1),('II',11,2,4,12,2,20,1),('III',1,12,15,0,3,30,1),('III',2,12,15,0,3,30,1),('III',3,10,6,2,2,20,1),('III',4,8,28,0,4,40,1),('III',5,6,7,20,7,40,1),('III',6,3,8,5,4,20,1),('III',7,3,9,5,3,20,1),('III',8,5,2,11,2,20,1),('III',9,4,10,20,6,40,1),('III',10,2,2,12,4,20,1),('III',11,2,4,12,2,20,1),('LEVEL 1',1,20,15,0,5,40,1),('LEVEL 1',2,20,15,0,5,40,1),('LEVEL 1',3,10,5,3,2,20,1),('LEVEL 1',4,5,20,0,5,30,1),('LEVEL 1',5,5,5,15,2,27,1),('LEVEL 1',6,3,3,10,4,20,1),('LEVEL 1',7,5,5,8,2,20,1),('LEVEL 1',8,5,3,10,2,20,1),('LEVEL 1',9,3,3,10,4,20,1),('LEVEL 1',10,3,3,10,4,20,1),('LEVEL 1',11,4,3,10,3,20,1);

SET FOREIGN_KEY_CHECKS = 1;
