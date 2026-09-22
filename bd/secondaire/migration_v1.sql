-- =====================================================================
--  SIGES — Migration SECONDAIRE v1 : rôle FONDATEUR + chef_etablissement_en
-- =====================================================================
--  Première migration de la série dédiée au secondaire
--  (bd/secondaire/migration_v*.sql) — numérotation INDÉPENDANTE de la série
--  primaire (bd/migration_v*.sql) : les deux ne sont jamais comparées entre
--  elles, chaque école ne consulte que la série de son propre type
--  (etablissement.type_enseignement), via assoc_migrations_etat()/
--  assoc_migrer_ecole() (connexion_assoc.php). Une école secondaire NEUVE
--  reçoit déjà tout ça directement via bd/assoc/schema_ref_ecole_secondaire.sql
--  (à jour) — cette migration ne sert qu'aux écoles secondaire existantes
--  créées avant ce jour.
--
--  Contenu (déjà appliqué à la main le 17/09/2026 sur l'unique école
--  secondaire existante à l'époque — ce fichier formalise ce changement
--  pour qu'il soit rejouable sur toute future école secondaire en retard) :
--   - `utilisateur`.role : ajout de la valeur 'FONDATEUR' (mêmes accès que
--     PROVISEUR partout, sauf licence/création d'année/infos établissement
--     — voir secondaire/pages/parametres/index.php et layout/menu_secondaire.php).
--   - `etablissement`.chef_etablissement_en : nom affiché sous « The
--     Principal » sur les documents en anglais (pendant de
--     chef_etablissement, déjà existant, affiché sous « LE PROVISEUR »).
--
--  Convention : SQL pur, autosuffisant, idempotent (rejouable sans risque).
-- =====================================================================

SET @a_fondateur := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'utilisateur' AND column_name = 'role'
      AND column_type LIKE '%FONDATEUR%');
SET @sql_role := IF(@a_fondateur = 0,
    "ALTER TABLE `utilisateur` MODIFY `role` enum('ADMIN','PROVISEUR','CENSEUR','SG','SECRETAIRE','ENSEIGNANT','INTENDANT','FONDATEUR') NOT NULL DEFAULT 'SECRETAIRE'",
    'SELECT 1');
PREPARE s1 FROM @sql_role; EXECUTE s1; DEALLOCATE PREPARE s1;

SET @a_chef_en := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'etablissement' AND column_name = 'chef_etablissement_en');
SET @sql_chef := IF(@a_chef_en = 0,
    "ALTER TABLE `etablissement` ADD COLUMN `chef_etablissement_en` varchar(150) DEFAULT 'The Principal' AFTER `chef_etablissement`",
    'SELECT 1');
PREPARE s2 FROM @sql_chef; EXECUTE s2; DEALLOCATE PREPARE s2;
